<?php

declare(strict_types=1);

namespace app\api\v1\middleware;

use app\model\Merchant;
use app\model\MerchantEncryption;
use Core\Traits\ApiResponse;
use Core\Utils\SignatureUtil;
use support\Log;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 统一API签名验证中间件
 *
 * 支持 GET/POST 请求的签名验证，遵循以下请求参数规范：
 * - merchant_number: 商户编号
 * - timestamp: 请求时间戳
 * - biz_content: 业务参数（Base64编码的JSON）
 * - sign_type: 签名算法类型
 * - sign: 签名
 */
class ApiSignatureVerification implements MiddlewareInterface
{
    use ApiResponse;

    /**
     * 时间戳有效期（秒）
     */
    private const int OFFSET_VALID_TIME = 600; // 10分钟

    /**
     * 必需参数错误提示
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
     *
     * @param Request  $request 请求对象
     * @param callable $handler 下一个处理器
     * @return Response 响应对象
     */
    public function process(Request $request, callable $handler): Response
    {
        try {
            // 获取并验证请求参数（支持 GET/POST）
            $params = $this->getRequestParams($request);

            // 验证所有参数
            $errorMessage = $this->validateAllParams($params);
            if ($errorMessage) {
                return $this->fail($errorMessage);
            }

            // 验证商户信息
            $merchant = $this->validateMerchant($params['merchant_number']);
            if (is_string($merchant)) {
                return $this->fail($merchant);
            }

            // 获取商户密钥配置
            $merchantEncryption = MerchantEncryption::find($merchant->id, ['mode', 'hash_key', 'rsa2_key']);
            if ($merchantEncryption === null) {
                return $this->fail('无法获取当前商户密钥配置');
            }

            // 执行所有验证
            $errorMessage = $this->performAllValidations($params, $merchantEncryption->toArray());
            if ($errorMessage) {
                return $this->fail($errorMessage);
            }

            // 将验证结果附加到请求中
            $this->attachToRequest($request, $merchant, $params);
        } catch (Throwable $e) {
            Log::error('API签名验证异常:' . $e->getMessage());
            return $this->error('签名验证异常');
        }

        return $handler($request);
    }

    /**
     * 获取请求参数
     *
     * 支持 GET 查询参数和 POST JSON/表单参数
     *
     * @param Request $request 请求对象
     * @return array 请求参数数组
     */
    private function getRequestParams(Request $request): array
    {
        // POST JSON 请求从 body 解析
        if ($request->method() === 'POST') {
            $contentType = $request->header('content-type', '');
            if (str_contains($contentType, 'application/json')) {
                $rawBody = $request->rawBody();
                $params  = json_validate($rawBody) ? json_decode($rawBody, true) : [];
            } else {
                $params = $request->post();
            }
        } else {
            $params = $request->get();
        }

        return [
            'merchant_number' => $params['merchant_number'] ?? '',
            'timestamp'       => isset($params['timestamp']) ? (string)$params['timestamp'] : '',
            'biz_content'     => $params['biz_content'] ?? '',
            'sign_type'       => $params['sign_type'] ?? '',
            'sign'            => $params['sign'] ?? ''
        ];
    }

    /**
     * 验证所有参数
     *
     * @param array $params 参数数组
     * @return string|null 错误消息，验证通过返回null
     */
    private function validateAllParams(array $params): ?string
    {
        // 基本参数验证
        $validations = [
            'merchant_number' => fn($v) => !empty($v),
            'biz_content'     => fn($v) => !empty($v),
            'timestamp'       => fn($v) => !empty($v) && is_numeric($v),
            'sign_type'       => fn($v) => !empty($v) && in_array($v, MerchantEncryption::SUPPORTED_SIGN_TYPES),
            'sign'            => fn($v) => !empty($v)
        ];

        foreach ($validations as $field => $validator) {
            if (!$validator($params[$field] ?? '')) {
                return self::REQUIRED_PARAMS[$field];
            }
        }

        // 商户编号格式验证
        if (!preg_match('/^M[A-Z0-9]{15}$/', $params['merchant_number'])) {
            return '商户编号(merchant_number)格式错误';
        }

        // 时间戳验证
        if (!is_numeric($params['timestamp']) || abs(time() - (int)$params['timestamp']) > self::OFFSET_VALID_TIME) {
            return '请求时间偏移过大';
        }

        return null;
    }

    /**
     * 验证商户信息
     *
     * @param string $merchantNumber 商户编号
     * @return Merchant|string 商户对象或错误消息
     */
    private function validateMerchant(string $merchantNumber): Merchant|string
    {
        $merchant = Merchant::where('merchant_number', $merchantNumber)->first(['id', 'merchant_number', 'email', 'mobile', 'diy_order_subject', 'buyer_pay_fee', 'status', 'risk_status', 'competence', 'channel_whitelist']);
        if (!$merchant) {
            return '该商户不可用';
        }

        // 检查商户状态和权限
        if ($merchant->status !== true || $merchant->risk_status === true || !$merchant->hasPermission('pay')) {
            return '无权限使用接口';
        }

        return $merchant;
    }

    /**
     * 执行所有验证
     *
     * @param array $params             参数数组
     * @param array $merchantEncryption 商户加密配置
     * @return string|null 错误消息，验证通过返回null
     */
    private function performAllValidations(array $params, array $merchantEncryption): ?string
    {
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
     * 验证签名算法类型
     *
     * @param string $signType 签名类型
     * @param string $mode     商户对接模式
     * @return bool 签名类型是否允许
     */
    private function validateSignType(string $signType, string $mode): bool
    {
        return match ($mode) {
            MerchantEncryption::MODE_ONLY_XXH => $signType === MerchantEncryption::SIGN_TYPE_XXH128,
            MerchantEncryption::MODE_ONLY_SHA3 => $signType === MerchantEncryption::SIGN_TYPE_SHA3_256,
            MerchantEncryption::MODE_ONLY_SM3 => $signType === MerchantEncryption::SIGN_TYPE_SM3,
            MerchantEncryption::MODE_ONLY_RSA2 => $signType === MerchantEncryption::SIGN_TYPE_SHA256withRSA,
            MerchantEncryption::MODE_OPEN => true,
            default => false
        };
    }

    /**
     * 将验证结果附加到请求
     *
     * @param Request  $request  请求对象
     * @param Merchant $merchant 商户对象
     * @param array    $params   验证后的参数
     * @return void
     */
    private function attachToRequest(Request $request, Merchant $merchant, array $params): void
    {
        $request->merchant       = $merchant;
        $request->verifiedParams = $params;
    }
}
