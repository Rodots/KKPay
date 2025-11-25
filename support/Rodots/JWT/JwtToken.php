<?php

declare(strict_types = 1);

namespace support\Rodots\JWT;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use SodiumException;
use support\Redis;
use support\Rodots\JWT\Exception\JwtTokenException;
use Throwable;

class JwtToken
{
    // 单例实例
    private static ?JwtToken $instance = null;

    // 加密算法
    private string $algorithm = 'EdDSA';
    // 过期时间，单位秒，默认15分钟有效
    private int $expireTime = 900;
    // 签名密钥（仅用于显式传入）
    private ?string $signingKey = null;
    // 验证密钥（仅用于显式传入）
    private ?string $validateKey = null;
    // 终端设备标识
    private ?string $device = 'web';
    // Redis缓存黑名单前缀
    private string $blacklistPrefix = 'JWTBlackList:';

    // 内存中缓存各算法的密钥对（静态，进程内共享）
    private static array $keyCache = [];

    // 支持的算法列表
    public static array $supportedAlgorithms = [
        'ES384'  => ['type' => 'openssl', 'curve' => 'secp384r1'],
        'ES256'  => ['type' => 'openssl', 'curve' => 'prime256v1'],
        'ES256K' => ['type' => 'openssl', 'curve' => 'secp256k1'],
        'HS256'  => ['type' => 'hmac', 'bits' => 256],
        'HS384'  => ['type' => 'hmac', 'bits' => 384],
        'HS512'  => ['type' => 'hmac', 'bits' => 512],
        'RS256'  => ['type' => 'rsa', 'bits' => 2048],
        'RS384'  => ['type' => 'rsa', 'bits' => 2048],
        'RS512'  => ['type' => 'rsa', 'bits' => 2048],
        'EdDSA'  => ['type' => 'sodium', 'bits' => null],
    ];

    /**
     * 私有构造函数，防止外部实例化
     */
    private function __construct() { }

    /**
     * 获取单例实例
     * @return JwtToken
     */
    public static function getInstance(): JwtToken
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 设置加密算法
     * @param string $algorithm
     * @return JwtToken
     */
    public function algo(string $algorithm): JwtToken
    {
        $this->algorithm = $algorithm;
        return $this;
    }

    /**
     * 设置过期时间
     * @param int $seconds
     * @return JwtToken
     */
    public function expire(int $seconds): JwtToken
    {
        $this->expireTime = $seconds;
        return $this;
    }

    /**
     * 设置签名密钥
     * @param string $signingKey
     * @return JwtToken
     */
    public function signingKey(string $signingKey): JwtToken
    {
        $this->signingKey = $signingKey;
        return $this;
    }

    /**
     * 设置验证密钥
     * @param string $validateKey
     * @return JwtToken
     */
    public function validateKey(string $validateKey): JwtToken
    {
        $this->validateKey = $validateKey;
        return $this;
    }

    /**
     * 设置设备标识
     * @param string $device
     * @return JwtToken
     */
    public function device(string $device): JwtToken
    {
        if (!in_array($device, ['web', 'mobile', 'pc'])) {
            $device = 'web';
        }
        $this->device = $device;
        return $this;
    }

    /**
     * 生成JWT Token
     * @param array $ext 拓展数据
     * @return string
     * @throws JwtTokenException
     */
    public function generate(array $ext = []): string
    {
        return $this->encode($ext);
    }

    /**
     * 验证并解析JWT Token
     * @param string $token JWT Token
     * @return array
     * @throws JwtTokenException
     */
    public function parse(string $token): array
    {
        if ($this->isTokenBlacklisted($token)) {
            throw new JwtTokenException('身份验证会话已过期，请重新登录！');
        }

        try {
            $decoded = FirebaseJWT::decode($token, new Key($this->getVerificationKey(), $this->algorithm));
            return json_decode(json_encode($decoded), true);
        } catch (SignatureInvalidException) {
            throw new JwtTokenException('身份验证令牌无效');
        } catch (BeforeValidException) {
            throw new JwtTokenException('身份验证令牌尚未生效');
        } catch (ExpiredException) {
            throw new JwtTokenException('身份验证会话已过期，请重新登录！');
        } catch (Throwable $e) {
            throw new JwtTokenException($e->getMessage());
        }
    }

    /**
     * 刷新Token
     * @param string $token 原Token
     * @return string 新的Token
     * @throws JwtTokenException
     */
    public function refresh(string $token): string
    {
        try {
            if (!$this->validate($token)) {
                throw new JwtTokenException('无效的Token，无法刷新');
            }
            $parse = $this->parse($token);
            $this->addToBlacklist($token, $parse['exp']);
            return $this->encode($parse['ext']);
        } catch (JwtTokenException $e) {
            throw new JwtTokenException($e->getMessage());
        } catch (Throwable $e) {
            throw new JwtTokenException('令牌刷新失败: ' . $e->getMessage());
        }
    }

    /**
     * 验证Token是否有效
     * @param string $token JWT Token
     * @return array|false
     * @throws JwtTokenException
     */
    public function validate(string $token): array|false
    {
        try {
            return $this->parse($token);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 注销Token
     * @param string $token JWT Token
     * @return bool
     * @throws JwtTokenException
     */
    public function invalidate(string $token): bool
    {
        try {
            $payload = $this->parse($token);
            $this->addToBlacklist($token, $payload['exp']);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 获取令牌中的所有扩展字段
     * @param string $token JWT Token
     * @return array
     * @throws JwtTokenException
     */
    public function getExtend(string $token): array
    {
        $payload = $this->parse($token);
        return $payload['ext'];
    }

    /**
     * 获取令牌中指定扩展字段的值
     * @param string $token   JWT Token
     * @param string $key     字段名
     * @param mixed  $default 默认值
     * @return mixed
     * @throws JwtTokenException
     */
    public function getExtendVal(string $token, string $key, mixed $default = null): mixed
    {
        $ext = $this->getExtend($token);
        if (str_contains($key, '.')) {
            $keys  = explode('.', $key); // 按 . 分割成数组
            $value = $ext;
            foreach ($keys as $k) {
                if (!is_array($value) || !array_key_exists($k, $value)) {
                    return $default;
                }
                $value = $value[$k];
            }
            return $value;
        }
        return $ext[$key] ?? $default;
    }

    /**
     * 获取令牌剩余有效期（单位：秒）
     * @param string $token JWT Token
     * @return int
     * @throws JwtTokenException
     */
    public function getTokenExp(string $token): int
    {
        $payload = $this->parse($token);
        $exp     = $payload['exp'] ?? 0;
        return max(0, $exp - time());
    }

    /**
     * 强制重新生成指定算法的密钥对
     * @param string $algo 算法名称
     * @return array 新的密钥对
     * @throws JwtTokenException
     */
    public function regenerateKeyPair(string $algo): array
    {
        if (!isset(self::$supportedAlgorithms[$algo])) {
            throw new JwtTokenException("不支持的算法: $algo");
        }

        // 清空内存缓存
        unset(self::$keyCache[$algo]);

        // 删除旧文件
        $certPath = config_path() . "/cert/jwt/$algo";
        foreach (['key.secret', 'private.key', 'public.key'] as $file) {
            $filePath = "$certPath/$file";
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // 重新生成密钥对并缓存
        return $this->generateKeyPair($algo);
    }

    /**
     * 编码JWT Token
     * @param mixed $ext 负载数据
     * @return string
     */
    private function encode(mixed $ext = null): string
    {
        $currentTime = time();
        $payload     = [
            'iss' => 'KKPay', // 签发者
            'sub' => $this->device, // 主题（这里使用终端设备标识）
            'iat' => $currentTime, // 签发时间
            'nbf' => $currentTime, // 生效时间
            'exp' => $currentTime + $this->expireTime, // 过期时间
            'jti' => uniqid(), // 唯一标识
            'ext' => $ext // 扩展字段
        ];

        return FirebaseJWT::encode($payload, $this->getSigningKey(), $this->algorithm);
    }

    /**
     * 获取签名密钥
     * @return string
     * @throws JwtTokenException
     */
    private function getSigningKey(): string
    {
        if ($this->signingKey !== null) {
            return $this->signingKey;
        }
        $keyPair = $this->getKeyPair($this->algorithm);
        $key     = $keyPair['privateKey'] ?? $keyPair['key'] ?? null;
        if ($key === null) {
            throw new JwtTokenException("无法获取{$this->algorithm}算法的密钥");
        }
        return $key;
    }

    /**
     * 获取验证密钥
     * @return string
     * @throws JwtTokenException
     */
    private function getVerificationKey(): string
    {
        if ($this->validateKey !== null) {
            return $this->validateKey;
        }
        $keyPair = $this->getKeyPair($this->algorithm);
        $secret  = $keyPair['publicKey'] ?? $keyPair['key'] ?? null;
        if ($secret === null) {
            throw new JwtTokenException("无法获取{$this->algorithm}算法的验证密钥");
        }
        return $secret;
    }

    /**
     * 将Token加入黑名单
     * @param string $token      JWT Token
     * @param int    $expireTime 多少秒后过期
     * @return void
     */
    private function addToBlacklist(string $token, int $expireTime): void
    {
        $ttl = $expireTime - time();
        if ($ttl > 1) {
            Redis::setex($this->blacklistPrefix . hash('xxh128', $token), $ttl, 0);
        }
    }

    /**
     * 检查Token是否在黑名单中
     * @param string $token JWT Token
     * @return int
     */
    private function isTokenBlacklisted(string $token): int
    {
        return Redis::exists($this->blacklistPrefix . hash('xxh128', $token));
    }

    /**
     * 获取指定算法的密钥对（优先内存 → 文件 → 生成）
     * @param string $algo 算法名称
     * @return array 密钥对数组
     * @throws JwtTokenException
     */
    private function getKeyPair(string $algo): array
    {
        if (!isset(self::$supportedAlgorithms[$algo])) {
            throw new JwtTokenException("不支持的算法: $algo");
        }

        // 1. 先从内存缓存获取
        if (isset(self::$keyCache[$algo])) {
            return self::$keyCache[$algo];
        }

        // 2. 尝试从文件加载
        $keyPair = $this->loadKeyFromFile($algo);
        if ($keyPair !== null) {
            self::$keyCache[$algo] = $keyPair;
            return $keyPair;
        }

        // 3. 文件不存在，自动生成
        $keyPair               = $this->generateKeyPair($algo);
        self::$keyCache[$algo] = $keyPair;

        return $keyPair;
    }

    /**
     * 从文件加载密钥对
     * @param string $algo
     * @return array|null 返回密钥对或 null（文件缺失）
     */
    private function loadKeyFromFile(string $algo): ?array
    {
        $certPath   = config_path() . "/cert/jwt/$algo";
        $algoConfig = self::$supportedAlgorithms[$algo];

        if ($algoConfig['type'] === 'hmac') {
            $keyFile = "$certPath/key.secret";
            if (file_exists($keyFile)) {
                return ['key' => file_get_contents($keyFile)];
            }
        } else {
            $privateFile = "$certPath/private.key";
            $publicFile  = "$certPath/public.key";
            if (file_exists($privateFile) && file_exists($publicFile)) {
                return [
                    'privateKey' => file_get_contents($privateFile),
                    'publicKey'  => file_get_contents($publicFile),
                ];
            }
        }
        return null; // 文件缺失
    }

    /**
     * 生成指定算法的密钥对并写入文件
     * @param string $algo 算法名称
     * @return array 密钥对数组
     * @throws JwtTokenException
     */
    private function generateKeyPair(string $algo): array
    {
        if (!isset(self::$supportedAlgorithms[$algo])) {
            throw new JwtTokenException("不支持的算法: $algo");
        }

        $certPath = config_path() . "/cert/jwt/$algo";
        if (!is_dir($certPath)) {
            mkdir($certPath, 0755, true);
        }

        $algoConfig = self::$supportedAlgorithms[$algo];

        switch ($algoConfig['type']) {
            case 'hmac':
                $key = random(128);
                file_put_contents("$certPath/key.secret", $key, LOCK_EX);
                return ['key' => $key];

            case 'rsa':
                $config = [
                    'private_key_bits' => $algoConfig['bits'],
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                ];
                $res    = openssl_pkey_new($config);
                if (!$res) {
                    throw new JwtTokenException('无法生成RSA密钥对: ' . openssl_error_string());
                }
                openssl_pkey_export($res, $privateKey);
                $publicKeyDetails = openssl_pkey_get_details($res);
                $publicKey        = $publicKeyDetails['key'];
                file_put_contents("$certPath/private.key", $privateKey, LOCK_EX);
                file_put_contents("$certPath/public.key", $publicKey, LOCK_EX);
                return ['privateKey' => $privateKey, 'publicKey' => $publicKey];

            case 'openssl':
                $config = [
                    'curve_name'       => $algoConfig['curve'],
                    'private_key_type' => OPENSSL_KEYTYPE_EC,
                ];
                $res    = openssl_pkey_new($config);
                if (!$res) {
                    throw new JwtTokenException('无法生成EC密钥对: ' . openssl_error_string());
                }
                openssl_pkey_export($res, $privateKey);
                $publicKeyDetails = openssl_pkey_get_details($res);
                $publicKey        = $publicKeyDetails['key'];
                file_put_contents("$certPath/private.key", $privateKey, LOCK_EX);
                file_put_contents("$certPath/public.key", $publicKey, LOCK_EX);
                return ['privateKey' => $privateKey, 'publicKey' => $publicKey];

            case 'sodium':
                try {
                    $keyPair    = sodium_crypto_sign_keypair();
                    $privateKey = base64_encode(sodium_crypto_sign_secretkey($keyPair));
                    $publicKey  = base64_encode(sodium_crypto_sign_publickey($keyPair));
                } catch (SodiumException $e) {
                    throw new JwtTokenException('无法生成EdDSA密钥对: ' . $e->getMessage());
                }
                file_put_contents("$certPath/private.key", $privateKey, LOCK_EX);
                file_put_contents("$certPath/public.key", $publicKey, LOCK_EX);
                return ['privateKey' => $privateKey, 'publicKey' => $publicKey];

            default:
                throw new JwtTokenException("不支持的算法类型: {$algoConfig['type']}");
        }
    }
}
