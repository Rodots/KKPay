<?php

declare(strict_types=1);

namespace Core\Gateway\Alipay\Lib;

/**
 * 支付宝配置对象（只读 DTO）
 */
readonly class AlipayConfig
{
    public const int KEY_MODE  = 0;
    public const int CERT_MODE = 1;

    public function __construct(
        public string  $appId,
        public ?string $privateKey = null,
        public ?string $alipayPublicKey = null,
        public ?string $alipayPublicKeyFilePath = null,
        public ?string $rootCertPath = null,
        public ?string $appCertPath = null,
        public int     $certMode = self::KEY_MODE,
        public ?string $encryptKey = null,
    )
    {
    }

    /**
     * 从数组创建配置对象
     *
     * 参数
     * - config: 包含 appId、私钥、公钥、证书路径、加密密钥、接口加签方式 等
     *
     */
    public static function fromArray(array $config): self
    {
        $certMode = (int)$config['certMode'];
        if (!in_array($certMode, [self::KEY_MODE, self::CERT_MODE], true)) {
            $certMode = self::KEY_MODE;
        }

        return new self(
            appId: $config['appId'] ?? '',
            privateKey: $config['privateKey'] ?? null,
            alipayPublicKey: $config['alipayPublicKey'] ?? null,
            alipayPublicKeyFilePath: $config['alipayPublicKeyFilePath'] ?? null,
            rootCertPath: $config['rootCertPath'] ?? null,
            appCertPath: $config['appCertPath'] ?? null,
            certMode: $certMode,
            encryptKey: $config['encryptKey'] ?? null,
        );
    }

    /**
     * 获取私钥内容
     */
    public function getPrivateKeyContent(): ?string
    {
        return $this->privateKey;
    }

    /**
     * 获取支付宝公钥内容（优先文件，其次内存）
     *
     * 返回
     * - PEM 格式公钥字符串，或 null（未配置）
     */
    public function getPublicKeyContent(): ?string
    {
        if ($this->alipayPublicKeyFilePath && file_exists($this->alipayPublicKeyFilePath)) {
            return file_get_contents($this->alipayPublicKeyFilePath) ?: null;
        }
        return $this->alipayPublicKey;
    }

    /**
     * 是否配置了可用的私钥
     */
    public function hasPrivateKey(): bool
    {
        return $this->getPrivateKeyContent() !== null;
    }

    /**
     * 是否配置了可用的支付宝公钥
     */
    public function hasPublicKey(): bool
    {
        return $this->getPublicKeyContent() !== null;
    }

    /**
     * 是否启用对称加密（存在 encryptKey 即视为启用）
     */
    public function isEncryptEnabled(): bool
    {
        return !empty($this->encryptKey);
    }

    /**
     * 是否使用证书模式（CERT_MODE）
     */
    public function isCertMode(): bool
    {
        return $this->certMode === self::CERT_MODE;
    }
}
