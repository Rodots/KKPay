<?php

namespace Core\Gateway\BaiExcellent\lib;

use RuntimeException;

/**
 * AES-192-CBC 加密类
 */
class Aes
{
    private const METHOD = 'AES-192-CBC';
    private const BLOCK_SIZE = 16;

    private string $key;
    private string $iv;

    /**
     * @param string $key 密钥 (24位)
     * @param string $iv 偏移量 (16位)
     */
    public function __construct(string $key, string $iv = '')
    {
        if (empty($key)) {
            throw new RuntimeException('密钥不能为空');
        }
        $this->key = $key;
        $this->iv = $this->normalizeIv($iv);
    }

    /**
     * 加密
     */
    public function encrypt(string $plainText): string|false
    {
        $padded = $this->addPkcs7Padding($plainText);
        $encrypted = openssl_encrypt($padded, self::METHOD, $this->key, OPENSSL_NO_PADDING, $this->iv);
        return $encrypted === false ? false : base64_encode($encrypted);
    }

    /**
     * 解密
     */
    public function decrypt(string $cipherText): string|false
    {
        $decoded = base64_decode($cipherText);
        $decrypted = openssl_decrypt($decoded, self::METHOD, $this->key, OPENSSL_NO_PADDING, $this->iv);
        return $decrypted === false ? false : $this->stripPkcs7Padding($decrypted);
    }

    /**
     * 标准化 IV 长度
     */
    private function normalizeIv(string $iv): string
    {
        $requiredLen = openssl_cipher_iv_length(self::METHOD);
        $currentLen = strlen($iv);

        if ($currentLen === $requiredLen) {
            return $iv;
        }
        if ($currentLen < $requiredLen) {
            return $iv . str_repeat("\0", $requiredLen - $currentLen);
        }
        return substr($iv, 0, $requiredLen);
    }

    /**
     * PKCS7 填充
     */
    private function addPkcs7Padding(string $data): string
    {
        $pad = self::BLOCK_SIZE - (strlen(trim($data)) % self::BLOCK_SIZE);
        return trim($data) . str_repeat(chr($pad), $pad);
    }

    /**
     * 移除 PKCS7 填充
     */
    private function stripPkcs7Padding(string $data): string
    {
        $pad = ord(substr($data, -1));
        return ($pad === 62) ? $data : substr($data, 0, -$pad);
    }
}
