<?php

declare(strict_types=1);

namespace Core\Gateway\Alipay\Lib;

use GuzzleHttp\Client;

/**
 * 支付宝客户端工厂
 *
 * 职责
 * - 提供多种构造便捷方法（密钥模式/证书模式/自定义 HTTP 客户端/沙箱）
 * - 统一封装 AlipayConfig 的创建逻辑
 */
readonly class Factory
{
    /**
     * 创建默认配置的 Guzzle HTTP 客户端
     */
    private static function createDefaultHttpClient(): Client
    {
        return new Client([
            'base_uri'    => AlipayClient::GATEWAY_URL,
            'timeout'     => AlipayClient::DEFAULT_TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * 使用密钥模式创建客户端
     *
     * @param string      $appId           应用 AppId
     * @param string      $privateKey      RSA 私钥（PKCS#1，PEM）
     * @param string|null $alipayPublicKey 支付宝公钥（可为空，若仅签名不验签）
     * @param string|null $encryptKey      可选，Base64 编码的 16 字节 AES 密钥
     */
    public static function createWithKeys(
        string  $appId,
        string  $privateKey,
        ?string $alipayPublicKey = null,
        ?string $encryptKey = null
    ): AlipayClient
    {
        return new AlipayClient(
            new AlipayConfig(
                appId: $appId,
                privateKey: $privateKey,
                alipayPublicKey: $alipayPublicKey,
                certMode: AlipayConfig::KEY_MODE,
                encryptKey: $encryptKey
            ),
            self::createDefaultHttpClient()
        );
    }

    /**
     * 使用证书模式创建客户端
     *
     * @param string      $appId          应用 AppId
     * @param string      $privateKey     应用私钥
     * @param string      $appCertPath    应用公钥证书路径（appCertPublicKey_*.crt）
     * @param string      $alipayCertPath 支付宝公钥证书路径（alipayCertPublicKey_RSA2.crt）
     * @param string      $rootCertPath   支付宝根证书路径（alipayRootCert.crt）
     * @param string|null $encryptKey     可选，Base64 编码的 16 字节 AES 密钥
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
        return new AlipayClient(
            new AlipayConfig(
                appId: $appId,
                privateKey: $privateKey,
                alipayPublicKeyFilePath: $alipayCertPath,
                rootCertPath: $rootCertPath,
                appCertPath: $appCertPath,
                certMode: AlipayConfig::CERT_MODE,
                encryptKey: $encryptKey
            ),
            self::createDefaultHttpClient()
        );
    }

    /**
     * 从配置数组创建客户端
     */
    public static function createFromArray(array $config): AlipayClient
    {
        return new AlipayClient(AlipayConfig::fromArray($config), self::createDefaultHttpClient());
    }

    /**
     * 使用自定义 Guzzle 客户端创建支付宝客户端
     */
    public static function createWithCustomClient(AlipayConfig $config, Client $httpClient): AlipayClient
    {
        return new AlipayClient($config, $httpClient);
    }
}
