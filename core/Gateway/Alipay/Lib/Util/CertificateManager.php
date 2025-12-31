<?php

declare(strict_types=1);

namespace Core\Gateway\Alipay\Lib\Util;

use Core\Gateway\Alipay\Lib\AlipayConfig;
use Exception;

/**
 * 证书管理器
 *
 * 职责
 * - 从证书内容解析应用证书序列号（app_cert_sn）与根证书序列号（alipay_root_cert_sn）
 * - 提取并规范化公钥内容，用于验签
 * - 处理多证书链分割与十六进制序列号转换
 */
readonly class CertificateManager
{
    private const array SUPPORTED_SIGNATURE_TYPES = [
        'sha1WithRSAEncryption',
        'sha256WithRSAEncryption'
    ];

    public function __construct(
        private AlipayConfig $config
    )
    {
    }

    /**
     * 从单个证书内容中提取序列号（SN）
     *
     * 返回
     * - md5(issuerDN + serialNumber)，与 Alipay 标准一致；失败返回 null
     */
    public static function extractSerialNumber(string $certContent): ?string
    {
        $certInfo = openssl_x509_parse($certContent);

        if (!is_array($certInfo) || !isset($certInfo['issuer'], $certInfo['serialNumber'])) {
            return null;
        }

        $issuerString = self::buildDistinguishedName(array_reverse((array)$certInfo['issuer']));
        return md5($issuerString . $certInfo['serialNumber']);
    }

    /**
     * 从证书内容中提取公钥（PEM）
     *
     * 返回
     * - 纯净公钥内容（去除头尾标记）；失败返回 null
     */
    public static function extractPublicKey(string $certContent): ?string
    {
        $publicKeyResource = openssl_pkey_get_public($certContent);
        if ($publicKeyResource === false) {
            return null;
        }

        $keyDetails = openssl_pkey_get_details($publicKeyResource);
        if (!isset($keyDetails['key'])) {
            return null;
        }

        return self::cleanPublicKey($keyDetails['key']);
    }

    /**
     * 从根证书内容中提取所有有效证书的序列号（拼接）
     *
     * 行为
     * - 仅保留签名算法为 sha1WithRSAEncryption/sha256WithRSAEncryption 的证书
     * - 支持 serialNumberHex 转十进制
     * - 返回使用下划线连接的多个 SN
     */
    public static function extractRootCertSerialNumbers(string $certContent): ?string
    {
        $certificates  = self::splitCertificates($certContent);
        $serialNumbers = [];

        foreach ($certificates as $cert) {
            $sn = self::processRootCertificate($cert);
            if ($sn !== null) {
                $serialNumbers[] = $sn;
            }
        }

        return empty($serialNumbers) ? null : implode('_', $serialNumbers);
    }

    /**
     * 获取应用公钥证书序列号
     *
     * @throws Exception 当无法读取证书文件时抛出
     */
    public function getAppCertSerialNumber(): ?string
    {
        if (!$this->config->isCertMode() || !$this->config->appCertPath) {
            return null;
        }

        $content = file_get_contents($this->config->appCertPath);
        if ($content === false) {
            throw new Exception("无法读取证书文件：{$this->config->appCertPath}", 400);
        }

        return self::extractSerialNumber($content);
    }

    /**
     * 获取根证书序列号集合
     *
     * @throws Exception 当无法读取证书文件时抛出
     */
    public function getRootCertSerialNumber(): ?string
    {
        if (!$this->config->isCertMode() || !$this->config->rootCertPath) {
            return null;
        }

        $content = file_get_contents($this->config->rootCertPath);
        if ($content === false) {
            throw new Exception("无法读取证书文件：{$this->config->rootCertPath}", 400);
        }

        return self::extractRootCertSerialNumbers($content);
    }

    /**
     * 加载支付宝公钥证书并返回其序列号
     *
     * @throws Exception 当无法从证书中提取序列号或公钥时抛出
     */
    public function loadAlipayPublicKeyCert(string $certContent): string
    {
        $serialNumber = self::extractSerialNumber($certContent);
        if ($serialNumber === null) {
            throw new Exception('无法从证书中提取序列号', 400);
        }

        $publicKey = self::extractPublicKey($certContent);
        if ($publicKey === null) {
            throw new Exception('无法从证书中提取公钥', 400);
        }

        return $serialNumber;
    }

    /**
     * 将证书链内容按 END 标记切分为多个单证书片段
     */
    private static function splitCertificates(string $certContent): array
    {
        return array_filter(
            explode('-----END CERTIFICATE-----', $certContent),
            static fn(string $part): bool => str_contains($part, '-----BEGIN CERTIFICATE-----')
        );
    }

    /**
     * 处理单个根证书，若合法返回其 SN
     */
    private static function processRootCertificate(string $cert): ?string
    {
        $fullCert = $cert . '-----END CERTIFICATE-----';
        $certInfo = openssl_x509_parse($fullCert);

        if (!is_array($certInfo)) {
            return null;
        }

        // 优先使用 serialNumberHex（更可靠）
        if (isset($certInfo['serialNumberHex'])) {
            $hex = $certInfo['serialNumberHex'];
            // 去掉可能的 0x 前缀
            if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
                $hex = substr($hex, 2);
            }
            // 确保是合法 hex
            if (!ctype_xdigit($hex)) {
                return null;
            }
            $serialNumber = self::hexToDec($hex);
        } elseif (isset($certInfo['serialNumber'])) {
            $serialNumber = (string)$certInfo['serialNumber'];
        } else {
            return null;
        }

        // 检查签名类型
        if (!in_array($certInfo['signatureTypeLN'] ?? '', self::SUPPORTED_SIGNATURE_TYPES, true)) {
            return null;
        }

        $issuerString = self::buildDistinguishedName(array_reverse((array)$certInfo['issuer']));
        return md5($issuerString . $serialNumber);
    }

    /**
     * 将 issuer DN 数组转换为 key=value 逗号拼接形式
     */
    private static function buildDistinguishedName(array $dn): string
    {
        return implode(',', array_map(
            static fn(string $key, mixed $value): string => "$key=$value",
            array_keys($dn),
            array_values($dn)
        ));
    }

    /**
     * 清理公钥内容，移除 PEM 头尾并去空白
     */
    private static function cleanPublicKey(string $publicKey): string
    {
        return trim(str_replace([
            '-----BEGIN PUBLIC KEY-----',
            '-----END PUBLIC KEY-----'
        ], '', $publicKey));
    }

    /**
     * 十六进制转十进制（高精度，使用 bcmath）
     */
    private static function hexToDec(string $hex): string
    {
        if ($hex === '') {
            return '0';
        }

        $dec = '0';
        $len = strlen($hex);

        for ($i = 0; $i < $len; $i++) {
            $digit = hexdec($hex[$i]);
            $power = bcpow('16', (string)($len - $i - 1));
            $dec   = bcadd($dec, bcmul((string)$digit, $power));
        }

        return $dec;
    }

}
