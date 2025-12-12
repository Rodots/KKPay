<?php
declare(strict_types = 1);

namespace support\Rodots\Crypto;

use Random\RandomException;

final class AES
{
    private const int    IV_BYTES      = 16;
    private const string CIPHER_METHOD = 'AES-128-CBC';
    private string $key;

    public function __construct(string $keyStr)
    {
        // 任意32位字符串
        $this->key = $keyStr;
    }

    /**
     * 加密 → base64（iv|ciphertext）
     * @throws RandomException
     */
    public function encrypt(string $plain): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $ct = openssl_encrypt(
            $plain,
            self::CIPHER_METHOD,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );
        if ($ct === false) {
            throw new \RuntimeException('AES encryption failed');
        }
        return base64_encode($iv . $ct);
    }

    /**
     * 解密
     */
    public function decrypt(string $b64): ?string
    {
        $data = base64_decode($b64, true);
        if ($data === false) return null;

        if (strlen($data) < self::IV_BYTES) return null;

        $iv = substr($data, 0, self::IV_BYTES);
        $ct = substr($data, self::IV_BYTES);

        $pt = openssl_decrypt(
            $ct,
            self::CIPHER_METHOD,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $pt === false ? null : $pt;
    }

    /**
     * 解密并返回JSON数组
     */
    public function get(string $b64): array
    {
        $data = $this->decrypt($b64);
        return $data === null ? [] : json_decode($data, true) ?? [];
    }
}
