<?php
declare(strict_types = 1);

namespace support\Rodots\Crypto;

use OpenSSLAsymmetricKey;
use RuntimeException;
use InvalidArgumentException;

final readonly class RSA2
{
    private const int SIGNATURE_ALGORITHM = OPENSSL_ALGO_SHA256;
    private const int PADDING = OPENSSL_PKCS1_PADDING;

    public function __construct(
        private OpenSSLAsymmetricKey $privateKey,
        private ?OpenSSLAsymmetricKey $publicKey = null
    ) {}

    /**
     * 从字符串创建RSA2实例
     */
    public static function fromString(string $privateKeyStr, string $publicKeyStr = ''): self
    {
        $privateKey = openssl_pkey_get_private($privateKeyStr);
        if ($privateKey === false) {
            throw new InvalidArgumentException('Invalid private key: ' . openssl_error_string());
        }

        $publicKey = null;
        if ($publicKeyStr !== '') {
            $publicKey = openssl_pkey_get_public($publicKeyStr);
            if ($publicKey === false) {
                throw new InvalidArgumentException('Invalid public key: ' . openssl_error_string());
            }
        } else {
            // 从私钥提取公钥
            $details = openssl_pkey_get_details($privateKey);
            if ($details === false || !isset($details['key'])) {
                throw new RuntimeException('Failed to extract public key from private key');
            }
            $publicKey = openssl_pkey_get_public($details['key']);
        }

        return new self($privateKey, $publicKey);
    }

    /**
     * 私钥加密 → base64
     */
    public function encrypt(string $plain): string
    {
        if (!openssl_private_encrypt($plain, $encrypted, $this->privateKey, self::PADDING)) {
            throw new RuntimeException('RSA private encryption failed: ' . openssl_error_string());
        }
        return base64_encode($encrypted);
    }

    /**
     * 公钥解密
     */
    public function decrypt(string $b64): ?string
    {
        $data = base64_decode($b64, true);
        if ($data === false) {
            return null;
        }

        if ($this->publicKey === null) {
            return null;
        }

        return openssl_public_decrypt($data, $decrypted, $this->publicKey, self::PADDING) 
            ? $decrypted 
            : null;
    }

    /**
     * 公钥加密 → base64
     */
    public function publicEncrypt(string $plain): string
    {
        if ($this->publicKey === null) {
            throw new RuntimeException('Public key not available');
        }

        if (!openssl_public_encrypt($plain, $encrypted, $this->publicKey, self::PADDING)) {
            throw new RuntimeException('RSA public encryption failed: ' . openssl_error_string());
        }
        return base64_encode($encrypted);
    }

    /**
     * 私钥解密
     */
    public function privateDecrypt(string $b64): ?string
    {
        $data = base64_decode($b64, true);
        if ($data === false) {
            return null;
        }

        return openssl_private_decrypt($data, $decrypted, $this->privateKey, self::PADDING) 
            ? $decrypted 
            : null;
    }

    /**
     * 私钥签名 → base64
     */
    public function sign(string $data): string
    {
        if (!openssl_sign($data, $signature, $this->privateKey, self::SIGNATURE_ALGORITHM)) {
            throw new RuntimeException('RSA signing failed: ' . openssl_error_string());
        }
        return base64_encode($signature);
    }

    /**
     * 公钥验签
     */
    public function verify(string $data, string $signatureB64): bool
    {
        if ($this->publicKey === null) {
            return false;
        }

        $signature = base64_decode($signatureB64, true);
        if ($signature === false) {
            return false;
        }

        $result = openssl_verify($data, $signature, $this->publicKey, self::SIGNATURE_ALGORITHM);
        return $result === 1;
    }

    /**
     * 解密并返回JSON数组
     */
    public function get(string $b64): array
    {
        $data = $this->decrypt($b64);
        if ($data === null) {
            return [];
        }

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 获取公钥PEM格式字符串
     */
    public function getPublicKeyPem(): ?string
    {
        if ($this->publicKey === null) {
            return null;
        }

        $details = openssl_pkey_get_details($this->publicKey);
        return $details['key'] ?? null;
    }

    /**
     * 获取私钥PEM格式字符串
     */
    public function getPrivateKeyPem(?string $passphrase = null): string
    {
        if (!openssl_pkey_export($this->privateKey, $output, $passphrase)) {
            throw new RuntimeException('Failed to export private key: ' . openssl_error_string());
        }
        return $output;
    }
}
