<?php

declare(strict_types = 1);

namespace Gateway\Alipay;

use GuzzleHttp\Client;

/**
 * 支付宝客户端工厂
 *
 * 职责
 * - 提供多种构造便捷方法（密钥模式/证书模式/自定义 HTTP 客户端/沙箱）
 * - 统一封装 AlipayConfig 的创建逻辑
 *
 * 注意
 * - 沙箱环境仅用于联调测试，生产必须使用正式域名与证书
 */
readonly class Factory
{
    /**
     * 使用密钥模式创建客户端
     *
     * 参数
     * - appId: 应用 AppId
     * - privateKey: RSA 私钥（PKCS#1，PEM）
     * - alipayPublicKey: 支付宝公钥（可为空，若仅签名不验签）
     * - encryptKey: 可选，Base64 编码的 16 字节 AES 密钥
     */
    public static function createWithKeys(
        string  $appId,
        string  $privateKey,
        ?string $alipayPublicKey = null,
        ?string $encryptKey = null
    ): AlipayClient
    {
        $config = new AlipayConfig(
            appId: $appId,
            privateKey: $privateKey,
            alipayPublicKey: $alipayPublicKey,
            certMode: AlipayConfig::KEY_MODE,
            encryptKey: $encryptKey
        );

        return AlipayClient::create($config);
    }

    /**
     * 使用证书模式创建客户端
     *
     * 参数
     * - appId: 应用 AppId
     * - privateKeyPath: 应用私钥
     * - appCertPath: 应用公钥证书路径（appCertPublicKey_*.crt）
     * - alipayCertPath: 支付宝公钥证书路径（alipayCertPublicKey_RSA2.crt）
     * - rootCertPath: 支付宝根证书路径（alipayRootCert.crt）
     * - encryptKey: 可选，Base64 编码的 16 字节 AES 密钥
     */
    public static function createWithCerts(
        string  $appId,
        string  $privateKey,
        string  $appCertPath,
        string  $alipayCertPath,
        string  $rootCertPath,
        ?string $encryptKey = null
    ): AlipayClient
    {
        $config = new AlipayConfig(
            appId: $appId,
            privateKey: $privateKey,
            alipayPublicKeyFilePath: $alipayCertPath,
            rootCertPath: $rootCertPath,
            appCertPath: $appCertPath,
            certMode: AlipayConfig::CERT_MODE,
            encryptKey: $encryptKey
        );

        return AlipayClient::create($config);
    }

    /**
     * 从配置数组创建客户端
     */
    public static function createFromArray(array $config): AlipayClient
    {
        return AlipayClient::create(AlipayConfig::fromArray($config));
    }

    /**
     * 使用自定义 Guzzle 客户端创建支付宝客户端
     */
    public static function createWithCustomClient(
        AlipayConfig $config,
        Client       $httpClient
    ): AlipayClient
    {
        return new AlipayClient($config, $httpClient);
    }
}
