<?php
declare(strict_types=1);

namespace support\Rodots\Crypto;

use Random\RandomException;
use RuntimeException;
use SensitiveParameter;

/**
 * AES 加密工具类
 * 默认采用 AES-256-GCM 模式，提供机密性和完整性校验。
 */
final readonly class AES
{
    private int $ivLength;

    /**
     * @param string $key        密钥 (AES-256 需要 32 字节, AES-128 需要 16 字节)
     * @param string $cipherAlgo 加密算法，推荐 AES-256-GCM
     */
    public function __construct(
        #[SensitiveParameter]
        private string $key,
        private string $cipherAlgo = 'aes-256-gcm'
    )
    {
        // 动态获取当前算法所需的 IV 长度
        $ivLen = openssl_cipher_iv_length($this->cipherAlgo);
        if ($ivLen === false) {
            throw new RuntimeException("Unknown cipher algorithm: {$this->cipherAlgo}");
        }
        $this->ivLength = $ivLen;

        // 严格校验密钥长度，防止 OpenSSL 静默截断或补零带来的安全隐患
        // 注意：这里简单假设 AES-X 中的 X 对应密钥位宽，严谨场景可手动映射
        if (str_contains($this->cipherAlgo, '256') && strlen($this->key) !== 32) {
            throw new RuntimeException("Algorithm {$this->cipherAlgo} requires a 32-byte key.");
        }
        if (str_contains($this->cipherAlgo, '128') && strlen($this->key) !== 16) {
            throw new RuntimeException("Algorithm {$this->cipherAlgo} requires a 16-byte key.");
        }
    }

    /**
     * 加密
     * 输出结构 (Base64): IV (Nonce) . [Tag if GCM] . Ciphertext
     *
     * @param string $plain 明文
     * @return string Base64 编码的加密字符串
     * @throws RandomException|RuntimeException
     */
    public function encrypt(#[SensitiveParameter] string $plain): string
    {
        $iv        = random_bytes($this->ivLength);
        $tag       = ""; // GCM 认证标签引用
        $tagLength = 16; // GCM 默认 Tag 长度

        $isGcm = $this->isGcm();

        $ciphertext = openssl_encrypt(
            $plain,
            $this->cipherAlgo,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            "", // aad
            $tagLength
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AES encryption failed via OpenSSL.');
        }

        // 组合数据：IV + (Tag) + 密文
        // GCM 模式必须保存 Tag 用于解密校验
        $payload = $iv . ($isGcm ? $tag : '') . $ciphertext;

        return base64_encode($payload);
    }

    /**
     * 解密
     *
     * @param string $b64 Base64 编码的密文
     * @return string|null 解密失败返回 null
     */
    public function decrypt(string $b64): ?string
    {
        $data = base64_decode($b64, true);
        if ($data === false) {
            return null;
        }

        $isGcm     = $this->isGcm();
        $tagLength = $isGcm ? 16 : 0;
        $minLen    = $this->ivLength + $tagLength;

        if (strlen($data) < $minLen) {
            return null;
        }

        // 提取 IV
        $iv = substr($data, 0, $this->ivLength);

        // 提取 Tag (仅 GCM)
        $tag = $isGcm ? substr($data, $this->ivLength, $tagLength) : "";

        // 提取密文
        $ciphertext = substr($data, $minLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $this->cipherAlgo,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * 解密并尝试解析 JSON
     *
     * @param string $b64
     * @return array
     */
    public function get(string $b64): array
    {
        $json = $this->decrypt($b64);

        if ($json === null) {
            return [];
        }

        if (!json_validate($json)) {
            return [];
        }

        return json_decode($json, true) ?? [];
    }

    /**
     * 判断是否为 GCM 模式
     */
    private function isGcm(): bool
    {
        return stripos($this->cipherAlgo, '-gcm') !== false;
    }
}
