<?php

declare(strict_types=1);

namespace app\api\v1\middleware;

use app\model\Merchant;
use app\model\MerchantEncryption;
use Core\Traits\ApiResponse;
use Core\Utils\SignatureUtil;
use support\Log;
use support\Rodots\Crypto\AES;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * API签名验证中间件
 */
class GetSignatureVerification implements MiddlewareInterface
{
    use ApiResponse;

    /**
     * 偏移有效期（秒）
     */
    private const int OFFSET_VALID_TIME = 600; // 10分钟

    /**
     * 必需参数列表
     */
    private const array REQUIRED_PARAMS = [
        'merchant_number' => '商户编号(merchant_number)缺失',
        'biz_content'     => '业务参数(biz_content)缺失',
        'timestamp'       => '请求时间戳(timestamp)格式错误',
        'sign_type'       => '签名算法类型(sign_type)不被允许',
        'sign'            => '签名缺失'
    ];

    /**
     * 处理请求
     */
    public function process(Request $request, callable $handler): Response
    {
        try {
            // 获取并验证请求参数
            $params       = $this->getRequestParams($request->all());
            $errorMessage = $this->validateAllParams($params);
            if ($errorMessage) {
                return $this->fail($errorMessage);
            }

            // 获取并验证商户信息
            $merchant = $this->getMerchantAndValidate($params['merchant_number']);
            if (is_string($merchant)) {
                return $this->fail($merchant);
            }

            // 获取商户密钥配置并处理加密参数
            $merchantEncryption = MerchantEncryption::find($merchant->id, ['mode', 'aes_key', 'hash_key', 'rsa2_key']);
            if ($merchantEncryption === null) {
                return $this->fail('无法获取当前商户密钥配置');
            }
            $merchantEncryption = $merchantEncryption->toArray();
            if (!empty($params['encryption_param'])) {
                $params = $this->processEncryptedParams($params, $merchantEncryption['aes_key']);
            }
            if (is_string($params)) {
                return $this->fail($params);
            }

            // 执行所有验证
            $errorMessage = $this->performAllValidations($params, $merchantEncryption);
            if ($errorMessage) {
                return $this->fail($errorMessage);
            }

            // 将验证结果添加到请求中
            $this->attachToRequest($request, $merchant, $params);
        } catch (Throwable $e) {
            Log::error('API签名验证异常:' . $e->getMessage());
            return $this->error('签名验证异常');
        }

        return $handler($request);
    }

    /**
     * 获取请求参数
     */
    private function getRequestParams(mixed $params): array
    {
        return [
            'merchant_number'  => $params['merchant_number'] ?? '',
            'encryption_param' => $params['encryption_param'] ?? null,
            'timestamp'        => $params['timestamp'] ?? '',
            'biz_content'      => $params['biz_content'] ?? '',
            'sign_type'        => $params['sign_type'] ?? '',
            'sign'             => $params['sign'] ?? ''
        ];
    }

    /**
     * 验证必需参数
     */
    private function validateRequiredParams(array $params): ?string
    {
        $validations = [
            'biz_content' => fn($v) => !empty($v),
            'timestamp'   => fn($v) => !empty($v) && is_numeric($v),
            'sign_type'   => fn($v) => !empty($v) && in_array($v, MerchantEncryption::SUPPORTED_SIGN_TYPES),
            'sign'        => fn($v) => !empty($v)
        ];

        foreach ($validations as $field => $validator) {
            if (!$validator($params[$field] ?? '')) {
                return self::REQUIRED_PARAMS[$field];
            }
        }

        return null;
    }

    /**
     * 验证所有参数
     */
    private function validateAllParams(array $params): ?string
    {
        // 验证商户编号格式
        if (empty($params['merchant_number'])) {
            return self::REQUIRED_PARAMS['merchant_number'];
        }
        if (!preg_match('/^M[A-Z0-9]{15}$/', $params['merchant_number'])) {
            return '商户编号(merchant_number)格式错误';
        }

        // 如果没有加密参数，则继续验证其他必传明文参数
        if (empty($params['encryption_param'])) {
            return $this->validateRequiredParams($params);
        }

        return null;
    }

    /**
     * 检查商户状态
     */
    private function checkMerchantStatus(Merchant $merchant): bool
    {
        return $merchant->status === true && $merchant->risk_status === false && $merchant->hasPermission('pay');
    }

    /**
     * 获取并验证商户信息
     */
    private function getMerchantAndValidate(string $merchantNumber): Merchant|string
    {
        if (!$merchant = Merchant::where('merchant_number', $merchantNumber)->first(['id', 'merchant_number', 'diy_order_subject', 'status', 'risk_status', 'competence'])) {
            return '该商户不可用';
        }

        if (!$this->checkMerchantStatus($merchant)) {
            return '商户权限不足';
        }

        return $merchant;
    }

    /**
     * 处理加密参数
     */
    private function processEncryptedParams(array $params, ?string $aesKey): array|string
    {
        if (empty($aesKey)) {
            return '商户未配置请求内容加密密钥';
        }

        try {
            $decryptedParams = new AES($aesKey)->get($params['encryption_param']);

            $errorMessage = $this->validateRequiredParams($decryptedParams);
            if ($errorMessage) {
                return $errorMessage;
            }

            // 合并参数并移除加密字段
            $mergedParams = array_merge($params, $decryptedParams);
            unset($mergedParams['encryption_param']);
            return $mergedParams;
        } catch (Throwable $e) {
            Log::error('参数AES解密失败:' . $e->getMessage());
            return '参数AES解密失败';
        }
    }

    /**
     * 验证时间戳
     */
    private function validateTimestamp(string $timestamp): bool
    {
        if (!is_numeric($timestamp)) {
            return false;
        }

        return abs(time() - (int)$timestamp) <= self::OFFSET_VALID_TIME;
    }

    /**
     * 验证签名算法类型
     */
    private function validateSignType(string $signType, string $mode): bool
    {
        return match ($mode) {
            'only_xxh' => $signType === MerchantEncryption::SIGN_TYPE_XXH128,
            'only_sha3' => $signType === MerchantEncryption::SIGN_TYPE_SHA3_256,
            'only_rsa2' => $signType === MerchantEncryption::SIGN_TYPE_SHA256withRSA,
            'open' => true,
            default => false
        };
    }

    /**
     * 执行所有验证
     */
    private function performAllValidations(array $params, array $merchantEncryption): ?string
    {
        // 验证时间戳
        if (!$this->validateTimestamp($params['timestamp'])) {
            return '请求时间偏移过大';
        }

        // 验证签名算法类型
        if (!$this->validateSignType($params['sign_type'], $merchantEncryption['mode'])) {
            return '签名算法类型(sign_type)不被允许';
        }

        // 验证签名
        if (!SignatureUtil::verifySignature($params, $params['sign_type'], $merchantEncryption)) {
            return '签名验证失败';
        }

        return null;
    }

    /**
     * 将验证结果附加到请求
     */
    private function attachToRequest(Request $request, Merchant $merchant, array $params): void
    {
        $request->merchant       = $merchant;
        $request->verifiedParams = $params;
    }
}
