<?php
declare(strict_types = 1);

namespace support\Rodots\Crypto;

use Random\RandomException;
use SodiumException;

final class XChaCha20
{
    private const int NONCE_BYTES = 24;
    private string $key;

    public function __construct(string $keyStr)
    {
        // 任意32位字符串
        $this->key = $keyStr;
    }

    /**
     * 加密 → base64（nonce|ciphertext|tag）
     * @throws RandomException
     * @throws SodiumException
     */
    public function encrypt(string $plain, string $aad = ''): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ct    = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plain,
            $aad,
            $nonce,
            $this->key
        );
        return base64_encode($nonce . $ct);
    }

    /**
     * 解密
     * @throws SodiumException
     */
    public function decrypt(string $b64, string $aad = ''): ?string
    {
        $data = base64_decode($b64, true);
        if ($data === false) return null;
        $nonce = substr($data, 0, self::NONCE_BYTES);
        $ct    = substr($data, self::NONCE_BYTES);
        $pt    = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ct,
            $aad,
            $nonce,
            $this->key
        );
        return $pt === false ? null : $pt;
    }

    /**
     * @throws SodiumException
     */
    public function get(string $b64, string $aad = ''): array
    {
        $data = $this->decrypt($b64, $aad);
        return $data === null ? [] : json_decode($data, true);
    }
}
