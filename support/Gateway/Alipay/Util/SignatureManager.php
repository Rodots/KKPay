<?php

declare(strict_types = 1);

namespace Gateway\Alipay\Util;

use Exception;
use Gateway\Alipay\AlipayConfig;

/**
 * 非对称签名管理器（RSA-SHA256）
 *
 * 职责
 * - 使用应用私钥对数据进行签名
 * - 使用支付宝公钥对数据进行验签
 * - 提供 V1/V2 参数签名/验签兼容方法
 *
 * 注意
 * - 私钥需为 PKCS#1 格式 PEM；公钥为 PEM
 * - 仅当配置中存在私钥时才认为需要签名
 */
readonly class SignatureManager
{
    public function __construct(
        private AlipayConfig $config
    )
    {
    }

    /**
     * 使用私钥对原文进行签名
     *
     * 返回
     * - Base64 编码的签名
     *
     * @throws Exception 当签名失败时抛出
     */
    public function sign(string $data): string
    {
        if (!$this->config->hasPrivateKey()) {
            throw new Exception('私钥缺失，请检查RSA私钥配置');
        }

        $privateKeyContent = $this->config->getPrivateKeyContent();
        $formattedKey      = $this->formatKey($privateKeyContent, 'PRIVATE KEY');
        $privateKey        = openssl_pkey_get_private($formattedKey);

        if (!$privateKey) {
            throw new Exception('私钥格式错误，请检查RSA私钥配置');
        }

        if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new Exception('签名失败：OpenSSL签名操作失败');
        }

        return base64_encode($signature);
    }

    /**
     * 使用支付宝公钥对原文进行验签
     *
     * 返回
     * - true 表示验签通过；false 表示未通过
     *
     * @throws Exception 当签名验证失败时抛出
     */
    public function verify(string $data, string $signature, ?string $publicKey = null): bool
    {
        // 如果没有提供公钥，则使用配置中的公钥
        if ($publicKey === null) {
            $publicKey = $this->config->getPublicKeyContent();
        }

        if (!$publicKey) {
            return !$this->config->hasPrivateKey(); // 如果不需要签名则跳过验证
        }

        // 如果是证书模式，公钥已经是从证书中提取的标准PEM格式，无需格式化
        if (!$this->config->isCertMode()) {
            $publicKey = $this->formatKey($publicKey, 'PUBLIC KEY');
        }

        $decodedSignature = base64_decode($signature, true);

        if ($decodedSignature === false) {
            throw new Exception('签名Base64解码失败');
        }

        return openssl_verify($data, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 对参数数组进行签名（按 key 升序拼接 "k=v" 且跳过值以 '@' 开头的项）
     *
     * @throws Exception 当签名失败时抛出
     */
    public function signParams(array $params): string
    {
        return $this->sign($this->buildSignContent($params));
    }

    /**
     * 验证参数签名（V1 规则：移除 sign 与 sign_type 后验签）
     *
     * @throws Exception 当签名验证失败时抛出
     */
    public function verifyParamsV1(array $params): bool
    {
        $signature = $params['sign'] ?? throw new Exception('签名失败：缺少签名参数');
        unset($params['sign'], $params['sign_type']);

        return $this->verify($this->buildSignContent($params), $signature);
    }

    /**
     * 验证参数签名（V3 规则：移除 sign 后验签）
     *
     * 生活号异步通知组成的待验签串里需要保留 sign_type 参数。
     *
     * @throws Exception 当签名验证失败时抛出
     */
    public function verifyParamsV3(array $params): bool
    {
        $signature = $params['sign'] ?? throw new Exception('签名失败：缺少签名参数');
        unset($params['sign']);

        return $this->verify($this->buildSignContent($params), $signature);
    }

    /**
     * 规范化 PEM 格式的密钥字符串。
     *
     * @param string $key    私钥内容
     * @param string $prefix 私钥标识
     * @return string
     */
    public function formatKey(string $key, string $prefix): string
    {
        $key = trim($key);

        if (str_contains($key, '-----BEGIN ' . $prefix . '-----')) {
            return $key;
        }

        return "-----BEGIN " . $prefix . "-----\n" .
            wordwrap($key, 64, "\n", true) .
            "\n-----END " . $prefix . "-----";
    }

    /**
     * 构建待签名原文
     *
     * 规则
     * - 对参数按键名升序
     * - 拼接为 "key=value" 键值对并用 '&' 连接
     * - 跳过以 '@' 开头的值（文件上传等）
     */
    private function buildSignContent(array $params): string
    {
        ksort($params);
        unset($params['sign']);
        $pairs = [];

        foreach ($params as $key => $value) {
            if ($value instanceof \CURLFile || $this->isEmpty($value) || str_starts_with((string)$value, '@')) continue;
            $pairs[] = "$key=$value";
        }

        return implode('&', $pairs);
    }

    /**
     * 校验某字符串或可被转换为字符串的数据，是否为 NULL 或均为空白字符.
     *
     * @param string|null $value
     *
     * @return bool
     */
    private function isEmpty(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
