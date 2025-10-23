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
    public const string DEVICE_WEB    = 'web';
    public const string DEVICE_MOBILE = 'mobile';
    public const string DEVICE_PC     = 'pc';

    // 单例实例
    private static ?JwtToken $instance = null;

    // 加密算法
    private string $algorithm = 'EdDSA';
    // 过期时间，单位秒，默认15分钟有效
    private int $expireTime = 900;
    // 签名密钥
    private ?string $signingKey = null;
    // 验证密钥
    private ?string $validateKey = null;
    // 终端设备标识
    private ?string $device = self::DEVICE_WEB;
    // Redis缓存前缀
    private string $redisPrefix = 'JWT:';
    // Redis缓存黑名单前缀
    private string $blacklistPrefix = 'JWT:blacklist:';
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
        if (!in_array($device, [self::DEVICE_WEB, self::DEVICE_MOBILE, self::DEVICE_PC])) {
            $device = self::DEVICE_WEB;
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

            $ext = $this->parse($token)['ext'];
            $this->addToBlacklist($token, time() + $this->expireTime);
            return $this->encode($ext);
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
            $exp     = $payload['exp'] ?? (time() + $this->expireTime);

            // 将Token加入黑名单
            $this->addToBlacklist($token, $exp);

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
        // 获取扩展数据
        $ext = $this->getExtend($token);

        // 判断是否需要递归解析
        if (str_contains($key, '.')) {
            $keys  = explode('.', $key); // 按 . 分割成数组
            $value = $ext;

            foreach ($keys as $k) {
                // 如果当前层级不存在或不是数组，则返回默认值
                if (!is_array($value) || !array_key_exists($k, $value)) {
                    return $default;
                }
                $value = $value[$k]; // 递归到下一层
            }

            return $value; // 返回最终找到的值
        }

        // 如果 key 不包含 .，直接返回对应的值或默认值
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

        $redisPrefix = $this->redisPrefix . $algo;

        // 清除Redis缓存
        Redis::del("$redisPrefix:privateKey");
        Redis::del("$redisPrefix:publicKey");
        Redis::del("$redisPrefix:key");

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
            // 'aud' => 'KKPay', // 接收者
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
     * @param int    $expireTime 黑名单过期时间
     * @return void
     */
    private function addToBlacklist(string $token, int $expireTime): void
    {
        $ttl = $expireTime - time();

        if ($ttl > 0) {
            Redis::setex($this->blacklistPrefix . hash('sha256', $token), $ttl, 0);
        }
    }

    /**
     * 检查Token是否在黑名单中
     * @param string $token JWT Token
     * @return int
     */
    private function isTokenBlacklisted(string $token): int
    {
        return Redis::exists($this->blacklistPrefix . hash('sha256', $token));
    }

    /**
     * 获取指定算法的密钥对
     * @param string $algo 算法名称
     * @return array 密钥对数组
     * @throws JwtTokenException
     */
    private function getKeyPair(string $algo): array
    {
        if (!isset(self::$supportedAlgorithms[$algo])) {
            throw new JwtTokenException("不支持的算法: $algo");
        }

        $redisPrefix = $this->redisPrefix . $algo;

        // 对称加密算法(HMAC)只需要一个密钥
        if (self::$supportedAlgorithms[$algo]['type'] === 'hmac') {
            return ['key' => Redis::get("$redisPrefix:key")];
        }

        // 非对称加密算法需要公钥和私钥
        return [
            'privateKey' => Redis::get("$redisPrefix:privateKey"),
            'publicKey'  => Redis::get("$redisPrefix:publicKey"),
        ];
    }

    /**
     * 生成指定算法的密钥对
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

        // 确保目录存在
        if (!is_dir($certPath)) {
            mkdir($certPath, 0644, true);
        }

        $redisPrefix = $this->redisPrefix . $algo;
        $algoConfig  = self::$supportedAlgorithms[$algo];

        // 根据算法类型生成不同的密钥
        switch ($algoConfig['type']) {
            case 'hmac':
                // 生成随机字符串作为HMAC密钥
                $key = random(128);
                file_put_contents("$certPath/key.secret", $key);
                Redis::set("$redisPrefix:key", $key);
                return ['key' => $key];

            case 'rsa':
                // 生成RSA密钥对
                $config = [
                    'private_key_bits' => $algoConfig['bits'],
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                ];
                $res    = openssl_pkey_new($config);
                if (!$res) {
                    throw new JwtTokenException('无法生成RSA密钥对: ' . openssl_error_string());
                }

                // 提取私钥
                openssl_pkey_export($res, $privateKey);

                // 提取公钥
                $publicKeyDetails = openssl_pkey_get_details($res);
                $publicKey        = $publicKeyDetails['key'];

                file_put_contents("$certPath/private.key", $privateKey);
                file_put_contents("$certPath/public.key", $publicKey);
                Redis::set("$redisPrefix:privateKey", $privateKey);
                Redis::set("$redisPrefix:publicKey", $publicKey);
                return [
                    'privateKey' => $privateKey,
                    'publicKey'  => $publicKey,
                ];

            case 'openssl':
                // 生成EC密钥对
                $config = [
                    'curve_name'       => $algoConfig['curve'],
                    'private_key_type' => OPENSSL_KEYTYPE_EC,
                ];
                $res    = openssl_pkey_new($config);
                if (!$res) {
                    throw new JwtTokenException('无法生成EC密钥对: ' . openssl_error_string());
                }

                // 提取私钥
                openssl_pkey_export($res, $privateKey);

                // 提取公钥
                $publicKeyDetails = openssl_pkey_get_details($res);
                $publicKey        = $publicKeyDetails['key'];

                file_put_contents("$certPath/private.key", $privateKey);
                file_put_contents("$certPath/public.key", $publicKey);
                Redis::set("$redisPrefix:privateKey", $privateKey);
                Redis::set("$redisPrefix:publicKey", $publicKey);
                return [
                    'privateKey' => $privateKey,
                    'publicKey'  => $publicKey,
                ];

            case 'sodium':
                // 生成EdDSA密钥对
                try {
                    $keyPair    = sodium_crypto_sign_keypair();
                    $privateKey = base64_encode(sodium_crypto_sign_secretkey($keyPair));
                    $publicKey  = base64_encode(sodium_crypto_sign_publickey($keyPair));
                } catch (SodiumException $e) {
                    throw new JwtTokenException('无法生成EdDSA密钥对: ' . $e->getMessage());
                }

                file_put_contents("$certPath/private.key", $privateKey);
                file_put_contents("$certPath/public.key", $publicKey);
                Redis::set("$redisPrefix:privateKey", $privateKey);
                Redis::set("$redisPrefix:publicKey", $publicKey);
                return [
                    'privateKey' => $privateKey,
                    'publicKey'  => $publicKey,
                ];

            default:
                throw new JwtTokenException("不支持的算法类型: {$algoConfig['type']}");
        }
    }
}
