<?php

declare(strict_types = 1);

namespace Gateway\Alipay\Util;

use Exception;
use Gateway\Alipay\AlipayConfig;
use Gateway\Alipay\Trait\HeaderUtilTrait;
use Random\RandomException;
use support\Rodots\Functions\Uuid;

/**
 * 安全编排管理器（对签名/验签/加解密/证书做统一编排）
 *
 * - 请求签名：生成 Authorization 头，证书模式自动附加 alipay-root-cert-sn/app_cert_sn
 * - 响应验签：解析头信息，选择合适公钥进行验签
 *   · 严格模式：缺少签名抛错；宽松模式：缺签名直接跳过
 * - 对称加密：委托 EncryptionManager 进行请求加密/响应解密
 *
 * 注意：不持久化敏感数据，只做流程编排。
 */
readonly class ConfigManager
{
    use HeaderUtilTrait;

    private SignatureManager   $signatureManager;
    private EncryptionManager  $encryptionManager;
    private CertificateManager $certificateManager;

    public function __construct(
        private AlipayConfig $config
    )
    {
        $this->signatureManager   = new SignatureManager($config);
        $this->encryptionManager  = new EncryptionManager($config);
        $this->certificateManager = new CertificateManager($config);
    }

    /**
     * 获取配置项的值
     */
    public function getValue(string $key): mixed
    {
        return $this->config->$key ?? null;
    }

    /**
     * 【V3协议】生成请求签名并设置 Authorization 头
     *
     * 参数
     * - httpMethod: HTTP 方法（如 POST）
     * - httpRequestUri: 路径（不含域名）
     * - httpRequestBody: 请求体（明文或密文）
     * - headerParams: 引用传入的头数组，将在其中写入 Authorization 等字段
     *
     * 行为
     * - 生成 nonce 与毫秒级 timestamp
     * - 构建 authString 与待签名内容，按需计算签名并写入 Authorization
     * - 证书模式下，附加 alipay-root-cert-sn 与 app_cert_sn（如可用）
     *
     * @throws Exception 当签名或证书处理失败时抛出
     */
    public function signV3(string $httpMethod, string $httpRequestUri, string $httpRequestBody, array &$headerParams): void
    {
        $appAuthToken = $this->getHeaderValue($headerParams, 'alipay-app-auth-token');
        $nonce        = $this->generateNonce();
        $timestamp    = $this->getCurrentMillis();

        $authString = $this->buildAuthString($nonce, $timestamp);
        $content    = $authString . "\n" . $httpMethod . "\n" . $httpRequestUri . "\n" . $httpRequestBody . "\n" . $appAuthToken;

        // 构建 Authorization 头
        $header = "ALIPAY-SHA256withRSA $authString";
        if ($this->config->hasPrivateKey()) {
            $signature = $this->signatureManager->sign($content);
            $header    .= ",sign=$signature";
        }
        $headerParams['Authorization'] = $header;

        // 添加证书相关头部
        if ($this->config->isCertMode()) {
            $rootCertSN = $this->certificateManager->getRootCertSerialNumber();
            if ($rootCertSN) {
                $headerParams['alipay-root-cert-sn'] = $rootCertSN;
            }
        }
    }

    public function signV1(array $params): string
    {
        return $this->signatureManager->signParams($params);
    }

    /**
     * 【V3协议】验证响应签名
     *
     * 参数
     * - responseBody: 响应体（明文，若密文需先解密）
     * - headers: 响应头
     * - isCheckSign: 是否开启严格验签；为 false 时宽松（缺签名直接跳过）
     *
     * 行为
     * - 从头中读取 alipay-sn/timestamp/nonce/signature
     * - 选择公钥：使用公钥文件或内存公钥
     * - 验签失败时抛出异常；缺公钥且无需签名时跳过
     *
     * @throws Exception 当验签失败或公钥证书不可用时抛出
     */
    public function verifyResponseV3(string $responseBody, array $headers, bool $isCheckSign = true): void
    {
        $signature = $this->getHeaderValue($headers, 'alipay-signature');

        // 严格模式：缺签名抛错；宽松模式：缺签名直接跳过
        if (empty($signature) || $signature === 'null') {
            if ($isCheckSign) {
                throw new Exception('响应缺少签名');
            }
            return;
        }

        $headerValues = $this->getHeaderValues($headers, [
            'alipay-sn',
            'alipay-timestamp',
            'alipay-nonce'
        ]);

        $publicKey = $this->getPublicKeyForVerification($headerValues['alipay-sn']);
        if (!$publicKey && $this->config->hasPrivateKey()) {
            throw new Exception('支付宝RSA公钥错误。请检查公钥文件格式是否正确');
        }

        $contentToVerify = $headerValues['alipay-timestamp'] . "\n" .
            $headerValues['alipay-nonce'] . "\n" .
            $responseBody . "\n";

        if (!$this->verifyWithPublicKey($contentToVerify, $signature, $publicKey)) {
            throw new Exception("签名验证失败: [sign=$signature, content=$responseBody]");
        }
    }

    /**
     * 验证响应签名
     *
     * @param string $responseBody
     * @param string $methodName
     * @return array
     * @throws Exception 当验签失败或公钥证书不可用时抛出
     */
    public function verifyResponseV1(string $responseBody, string $methodName): array
    {
        if (!json_validate($responseBody)) {
            throw new Exception('支付宝返回数据格式错误');
        }

        $responseArr = json_decode($responseBody, true);
        $results     = $responseArr[str_replace('.', '_', $methodName) . '_response'];
        $signature   = $responseArr['sign'];

        $publicKey = $this->getPublicKeyForVerification($responseArr['alipay_cert_sn']);
        if (!$publicKey && $this->config->hasPrivateKey()) {
            throw new Exception('支付宝RSA公钥错误。请检查公钥文件格式是否正确');
        }

        if (!$this->verifyWithPublicKey(json_encode($results), $signature, $publicKey)) {
            throw new Exception("签名验证失败: [content=$responseBody]");
        }

        return $results;
    }

    /**
     * 加密请求体
     */
    public function encryptRequest(string $requestBody): string
    {
        return $this->encryptionManager->encrypt($requestBody);
    }

    /**
     * 解密响应体
     */
    public function decryptResponse(string $responseBody): string
    {
        return $this->encryptionManager->decrypt($responseBody);
    }

    /**
     * 生成随机 nonce
     */
    private function generateNonce(): string
    {
        try {
            return Uuid::v4();
        } catch (RandomException) {
            return random(32);
        }
    }

    /**
     * 构建授权字符串（不含签名）
     *
     * 形如
     * - app_id=xxx,app_cert_sn=xxx,nonce=...,timestamp=...
     *
     * @throws Exception 当证书处理失败时抛出
     */
    private function buildAuthString(string $nonce, string $timestamp): string
    {
        $authString = "app_id={$this->config->appId}";

        $appCertSN = $this->certificateManager->getAppCertSerialNumber();
        if ($appCertSN) {
            $authString .= ",app_cert_sn=$appCertSN";
        }

        return "$authString,nonce=$nonce,timestamp=$timestamp";
    }

    /**
     * 选择用于验签的公钥
     *
     * 优先级
     * - 尝试从 alipayPublicKeyFilePath 读取
     * - 否则使用内存中的 alipayPublicKey
     *
     * @throws Exception 当公钥证书过期时抛出
     */
    private function getPublicKeyForVerification(?string $serialNumber): ?string
    {
        // 尝试从公钥文件加载
        if ($this->config->alipayPublicKeyFilePath) {
            $key = file_get_contents($this->config->alipayPublicKeyFilePath) ?: null;
        } else {
            // 使用公钥字符串
            $key = $this->config->alipayPublicKey;
        }

        // 证书模式下：如果提供了序列号则校验序列号是否正确
        if (!empty($this->config->appCertPath) && !empty($serialNumber)) {
            if ($serialNumber !== $this->certificateManager->loadAlipayPublicKeyCert($key)) {
                throw new Exception("支付宝公钥证书[$serialNumber]已过期，请重新下载最新支付宝公钥证书");
            }
        }

        return $key;
    }

    /**
     * 使用公钥验证签名
     *
     * 返回
     * - true 表示验签通过；false 表示失败
     */
    private function verifyWithPublicKey(string $content, string $signature, string $publicKey): bool
    {
        // 检查是否是证书格式，如果是则从中提取公钥
        if (str_contains($publicKey, '-----BEGIN CERTIFICATE-----')) {
            $publicKey = $this->certificateManager->extractPublicKey($publicKey) ?? $publicKey;
        }
        
        $formattedKey     = $this->signatureManager->formatKey($publicKey, 'PUBLIC KEY');
        $decodedSignature = base64_decode($signature, true);

        if ($decodedSignature === false) {
            return false;
        }

        return openssl_verify($content, $decodedSignature, $formattedKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 获取当前毫秒时间戳
     */
    private function getCurrentMillis(): string
    {
        [$micro, $sec] = explode(' ', microtime());
        return sprintf('%d%03d', $sec, (int)($micro * 1000));
    }

    /**
     * 构建公共请求参数
     *
     * @param array  $params     业务参数，将被 JSON 编码为 biz_content
     * @param string $methodName 支付宝接口方法名，例如 'alipay.trade.page.pay'
     * @param string $returnUrl  支付完成后同步跳转回商户页面的 URL
     * @param string $notifyUrl  支付结果异步通知地址（服务器回调）
     * @return array
     */
    public function buildRequestParams(array $params, string $methodName, string $returnUrl, string $notifyUrl): array
    {
        $commonParams = [
            'app_id'      => $this->getValue('appId'),
            'method'      => $methodName,
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'return_url'  => $returnUrl,
            'notify_url'  => $notifyUrl,
            'biz_content' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ];

        // 添加证书相关参数
        if ($this->config->isCertMode()) {
            $commonParams['app_cert_sn']         = $this->certificateManager->getAppCertSerialNumber();
            $commonParams['alipay_root_cert_sn'] = $this->certificateManager->getRootCertSerialNumber();
        }

        $commonParams['sign'] = $this->signV1($commonParams);

        return $commonParams;
    }
}