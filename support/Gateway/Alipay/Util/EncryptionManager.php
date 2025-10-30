<?php

declare(strict_types = 1);

namespace Gateway\Alipay\Util;

use Exception;
use Gateway\Alipay\AlipayConfig;

use Gateway\Alipay\Trait\CryptoUtilTrait;
use Throwable;

/**
 * 对称加密管理器（AES-128-CBC）
 *
 * 职责
 * - 按照 Alipay 规范对请求体进行加密/对响应体解密
 * - 采用 PKCS7 填充与零 IV（16 字节 0x00）
 *
 * 安全说明
 * - encryptKey 需为 Base64 编码的 16 字节密钥；务必妥善保管
 * - 不负责对明文/密文做敏感信息脱敏，日志请谨慎打印
 */
readonly class EncryptionManager
{
    use CryptoUtilTrait;

    private const string ALGORITHM = 'aes-128-cbc';
    private const int    IV_LENGTH = 16;

    public function __construct(
        private AlipayConfig $config
    )
    {
    }

    /**
     * 加密明文
     *
     * @throws Exception 当加密失败时抛出
     */
    public function encrypt(string $plainText): string
    {
        if (empty($plainText)) {
            return '';
        }

        try {
            $secretKey  = base64_decode($this->config->encryptKey);
            $paddedText = $this->addPKCS7Padding(trim($plainText));
            $iv         = str_repeat("\0", self::IV_LENGTH);

            $encryptedData = openssl_encrypt(
                $paddedText,
                self::ALGORITHM,
                $secretKey,
                OPENSSL_NO_PADDING,
                $iv
            );

            if ($encryptedData === false) {
                throw new Exception("AES加密失败，plainText={$plainText}，OpenSSL加密失败", 400);
            }

            return base64_encode($encryptedData);
        } catch (Throwable $e) {
            throw new Exception(
                "AES加密失败，plainText={$plainText}，keySize=" . strlen($this->config->encryptKey ?? '') . ", " . $e->getMessage(),
                400
            );
        }
    }

    /**
     * 解密密文
     *
     * @throws Exception 当解密失败时抛出
     */
    public function decrypt(string $cipherText): string
    {
        if (empty($cipherText)) {
            return '';
        }

        try {
            $encryptedData = base64_decode($cipherText);
            $secretKey     = base64_decode($this->config->encryptKey);
            $iv            = str_repeat("\0", self::IV_LENGTH);

            $decryptedData = openssl_decrypt(
                $encryptedData,
                self::ALGORITHM,
                $secretKey,
                OPENSSL_NO_PADDING,
                $iv
            );

            if ($decryptedData === false) {
                throw new Exception("AES解密失败，cipherText={$cipherText}，OpenSSL解密失败", 400);
            }

            return $this->stripPKCS7Padding($decryptedData);
        } catch (Throwable $e) {
            throw new Exception(
                "AES解密失败，cipherText={$cipherText}，keySize=" . strlen($this->config->encryptKey ?? '') . ", " . $e->getMessage(),
                400
            );
        }
    }
}
