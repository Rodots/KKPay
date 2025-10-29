<?php

declare(strict_types = 1);

namespace support\Rodots\Crypto;

use OpenSSLAsymmetricKey;
use RuntimeException;
use InvalidArgumentException;
use JsonException;

/**
 * RSA2 工具类，封装基于 OpenSSL 的 RSA 加密、解密、签名与验签操作。
 */
final readonly class RSA2
{
    private const int SIGNATURE_ALGORITHM = OPENSSL_ALGO_SHA256;
    private const int PADDING             = OPENSSL_PKCS1_PADDING;

    /**
     * 构造函数。
     *
     * @param OpenSSLAsymmetricKey|null $privateKey 私钥资源
     * @param OpenSSLAsymmetricKey|null $publicKey  公钥资源
     * @throws InvalidArgumentException 如果未提供任何密钥
     */
    public function __construct(
        private ?OpenSSLAsymmetricKey $privateKey = null,
        private ?OpenSSLAsymmetricKey $publicKey = null
    )
    {
        if ($this->privateKey === null && $this->publicKey === null) {
            throw new InvalidArgumentException('至少需要提供一个密钥（私钥或公钥）');
        }
    }

    /**
     * 从私钥字符串创建实例。
     *
     * 自动规范化私钥格式（支持纯 Base64、修复 \n、添加 PEM 头尾）。
     *
     * @param string      $privateKeyStr PEM 或纯 Base64 格式的私钥字符串
     * @param string|null $publicKeyStr  可选的 PEM 公钥字符串；若为 null，则不加载公钥
     * @return self
     * @throws InvalidArgumentException 当私钥或提供的公钥无效时
     */
    public static function fromPrivateKey(string $privateKeyStr, ?string $publicKeyStr = null): self
    {
        $normalizedPrivateKey = self::normalizePem($privateKeyStr, 'PRIVATE KEY');
        $privateKey           = openssl_pkey_get_private($normalizedPrivateKey);
        if ($privateKey === false) {
            throw new InvalidArgumentException('无效的私钥: ' . openssl_error_string());
        }

        $publicKey = null;
        if ($publicKeyStr !== null) {
            $normalizedPublicKey = self::normalizePem($publicKeyStr, 'PUBLIC KEY');
            $publicKey           = openssl_pkey_get_public($normalizedPublicKey);
            if ($publicKey === false) {
                throw new InvalidArgumentException('无效的公钥: ' . openssl_error_string());
            }
        }

        return new self($privateKey, $publicKey);
    }

    /**
     * 从公钥字符串创建实例。
     *
     * @param string $publicKeyStr PEM 或纯 Base64 格式的公钥字符串
     * @return self
     * @throws InvalidArgumentException 当公钥无效时
     */
    public static function fromPublicKey(string $publicKeyStr): self
    {
        $normalizedPublicKey = self::normalizePem($publicKeyStr, 'PUBLIC KEY');
        $publicKey           = openssl_pkey_get_public($normalizedPublicKey);
        if ($publicKey === false) {
            throw new InvalidArgumentException('无效的公钥: ' . openssl_error_string());
        }

        return new self(null, $publicKey);
    }

    /**
     * 使用公钥加密明文，并返回 Base64 编码结果。
     *
     * 适用于：发送方用接收方公钥加密数据。
     *
     * @param string $plain 明文数据
     * @return string Base64 编码的密文
     * @throws RuntimeException 当缺少公钥或加密失败时
     */
    public function publicEncrypt(string $plain): string
    {
        if ($this->publicKey === null) {
            throw new RuntimeException('公钥加密需要公钥');
        }

        if (!openssl_public_encrypt($plain, $encrypted, $this->publicKey, self::PADDING)) {
            throw new RuntimeException('RSA公钥加密失败: ' . openssl_error_string());
        }
        return base64_encode($encrypted);
    }

    /**
     * 使用私钥解密 Base64 编码的密文。
     *
     * 适用于：接收方用自己的私钥解密数据。
     *
     * @param string $b64 Base64 编码的密文
     * @return string|null 解密后的明文；若解密失败（如密文损坏或密钥不匹配）则返回 null
     * @throws RuntimeException 当缺少私钥时
     */
    public function privateDecrypt(string $b64): ?string
    {
        if ($this->privateKey === null) {
            throw new RuntimeException('私钥解密需要私钥');
        }

        $data = base64_decode($b64, true);
        if ($data === false) {
            return null;
        }

        return openssl_private_decrypt($data, $decrypted, $this->privateKey, self::PADDING) ? $decrypted : null;
    }

    /**
     * 使用私钥对数据进行签名，并返回 Base64 编码的签名。
     *
     * @param string $data 待签名的数据
     * @return string Base64 编码的签名
     * @throws RuntimeException 当缺少私钥或签名失败时
     */
    public function sign(string $data): string
    {
        if ($this->privateKey === null) {
            throw new RuntimeException('签名需要私钥');
        }

        if (!openssl_sign($data, $signature, $this->privateKey, self::SIGNATURE_ALGORITHM)) {
            throw new RuntimeException('RSA签名失败: ' . openssl_error_string());
        }
        return base64_encode($signature);
    }

    /**
     * 使用公钥验证数据签名。
     *
     * @param string $data         原始数据
     * @param string $signatureB64 Base64 编码的签名
     * @return bool 签名有效返回 true，否则 false
     * @throws RuntimeException 当缺少公钥时
     */
    public function verify(string $data, string $signatureB64): bool
    {
        if ($this->publicKey === null) {
            throw new RuntimeException('验证需要公钥');
        }

        $signature = base64_decode($signatureB64, true);
        if ($signature === false) {
            return false;
        }

        $result = openssl_verify($data, $signature, $this->publicKey, self::SIGNATURE_ALGORITHM);
        return $result === 1;
    }

    /**
     * 解密 Base64 密文并解析为 JSON 数组。
     *
     * 本方法会先调用 privateDecrypt() 解密，再解析 JSON。
     * 若解密失败或 JSON 无效，将抛出异常（不再静默返回空数组）。
     *
     * @param string $b64 Base64 编码的密文
     * @return array 解密并解析后的关联数组
     * @throws RuntimeException 当解密失败时
     * @throws JsonException 当 JSON 格式无效或结果不是数组时
     */
    public function get(string $b64): array
    {
        $decrypted = $this->privateDecrypt($b64);
        if ($decrypted === null) {
            throw new RuntimeException('解密失败：密文无效或密钥不匹配');
        }

        $decoded = json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException('解密后的内容不是有效的 JSON 数组');
        }

        return $decoded;
    }

    /**
     * 获取公钥的 PEM 格式字符串。
     *
     * - 如果实例已持有公钥，直接返回；
     * - 否则，若持有私钥，则动态从私钥提取公钥（不缓存）；
     * - 否则返回 null。
     *
     * 注意：从私钥提取公钥是轻量操作，但每次调用都会重新计算。
     *
     * @return string|null PEM 格式的公钥，或 null
     */
    public function getPublicKeyPem(): ?string
    {
        if ($this->publicKey !== null) {
            $details = openssl_pkey_get_details($this->publicKey);
            return $details['key'] ?? null;
        }

        if ($this->privateKey !== null) {
            $details = openssl_pkey_get_details($this->privateKey);
            return $details['key'] ?? null;
        }

        return null;
    }

    /**
     * 获取当前私钥的 PEM 格式字符串（可选加密导出）。
     *
     * 注意：本方法不支持密码短语（因未实现），若需密码保护请自行扩展。
     *
     * @param string|null $passphrase 保留参数（当前未使用）
     * @return string|null PEM 格式的私钥；若无私钥则返回 null
     * @throws RuntimeException 当导出失败时
     */
    public function getPrivateKeyPem(?string $passphrase = null): ?string
    {
        if ($this->privateKey === null) {
            return null;
        }

        // 注意：当前未使用 $passphrase，如需支持需调用 openssl_pkey_export 的密码参数
        if (!openssl_pkey_export($this->privateKey, $output, $passphrase)) {
            throw new RuntimeException('导出私钥失败: ' . openssl_error_string());
        }
        return $output;
    }

    /**
     * 判断是否持有私钥。
     *
     * @return bool
     */
    public function hasPrivateKey(): bool
    {
        return $this->privateKey !== null;
    }

    /**
     * 判断是否持有公钥。
     *
     * @return bool
     */
    public function hasPublicKey(): bool
    {
        return $this->publicKey !== null;
    }

    /**
     * 规范化 PEM 格式的密钥字符串。
     *
     * @param string $key    原始密钥字符串（PEM 或纯 Base64）
     * @param string $prefix 用于检测是否已为 PEM 格式的关键词，如 'PRIVATE KEY'
     * @return string 标准 PEM 格式字符串
     */
    private static function normalizePem(string $key, string $prefix): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException("密钥不能为空");
        }

        // 修复字面量 \n 和 \r
        $key = str_replace('\n', "\n", $key);
        $key = str_replace('\r', '', $key);

        // 如果已经是标准 PEM 格式，直接返回
        if (preg_match('/-----BEGIN\s+' . preg_quote($prefix, '/') . '\s*-----/', $key)) {
            return $key;
        }

        // 提取纯 Base64 内容（移除非 Base64 字符）
        $base64 = preg_replace('/[^a-zA-Z0-9+\/=]/', '', $key);
        if ($base64 === '' || strlen($base64) < 10) {
            throw new InvalidArgumentException("密钥内容无效：无法提取有效的 Base64 数据");
        }

        // 按 PEM 标准每行 64 字符换行
        $wrapped = wordwrap($base64, 64, "\n", true);

        return "-----BEGIN {$prefix}-----\n{$wrapped}\n-----END {$prefix}-----";
    }
}
