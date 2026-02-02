<?php

declare(strict_types=1);

namespace Core\Utils;

use Exception;
use support\Log;
use support\Rodots\Crypto\RSA2;
use Throwable;

/**
 * 签名工具类
 * 提供统一的签名生成和验证方法
 */
final class SignatureUtil
{
    /**
     * 构建签名字符串
     *
     * @param array $params 参数数组
     * @return string 签名字符串
     */
    public static function buildSignString(array $params): string
    {
        unset($params['sign']);

        $filtered = array_filter($params, fn($value) => !is_array($value) && $value !== null && (!is_string($value) || trim($value) !== ''));

        ksort($filtered);

        return implode('&', array_map(fn($key, $value) => $key . '=' . $value, array_keys($filtered), $filtered));
    }

    /**
     * 验证xxHash128哈希签名
     *
     * @param string      $signString 签名字符串
     * @param string      $signature  待验证签名
     * @param string|null $hashKey    哈希密钥
     * @return bool 验证结果
     */
    public static function verifyxxHash128Signature(string $signString, string $signature, ?string $hashKey): bool
    {
        if (empty($hashKey)) {
            return false;
        }

        $expectedSignature = hash('xxh128', $signString . $hashKey);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * 验证SHA3-256哈希签名
     *
     * @param string      $signString 签名字符串
     * @param string      $signature  待验证签名
     * @param string|null $hashKey    哈希密钥
     * @return bool 验证结果
     */
    public static function verifySha3Signature(string $signString, string $signature, ?string $hashKey): bool
    {
        if (empty($hashKey)) {
            return false;
        }

        $expectedSignature = hash('sha3-256', $signString . $hashKey);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * 验证SM3哈希签名（国密）
     *
     * @param string      $signString 签名字符串
     * @param string      $signature  待验证签名
     * @param string|null $hashKey    哈希密钥
     * @return bool 验证结果
     */
    public static function verifySm3Signature(string $signString, string $signature, ?string $hashKey): bool
    {
        if (empty($hashKey)) {
            return false;
        }

        $expectedSignature = openssl_digest($signString . $hashKey, 'sm3');
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * 验证SHA256withRSA签名
     *
     * @param string      $signString 签名字符串
     * @param string      $signature  待验证签名
     * @param string|null $publicKey  公钥
     * @return bool 验证结果
     */
    public static function verifyRsa2Signature(string $signString, string $signature, ?string $publicKey): bool
    {
        if (empty($publicKey)) {
            return false;
        }

        try {
            return RSA2::fromPublicKey($publicKey)->verify($signString, $signature);
        } catch (Throwable $e) {
            Log::error('RSA2签名验证失败:' . $e->getMessage());
            return false;
        }
    }

    /**
     * 验证签名
     *
     * @param array  $params             参数数组
     * @param string $signType           签名类型
     * @param array  $merchantEncryption 商户加密配置对象
     * @return bool 验证结果
     */
    public static function verifySignature(array $params, string $signType, array $merchantEncryption): bool
    {
        try {
            $signString = self::buildSignString($params);

            return match ($signType) {
                'xxh' => self::verifyxxHash128Signature($signString, $params['sign'], $merchantEncryption['hash_key']),
                'sha3' => self::verifySha3Signature($signString, $params['sign'], $merchantEncryption['hash_key']),
                'sm3' => self::verifySm3Signature($signString, $params['sign'], $merchantEncryption['hash_key']),
                'rsa2' => self::verifyRsa2Signature($signString, $params['sign'], $merchantEncryption['rsa2_key']),
                default => false
            };
        } catch (Throwable $e) {
            Log::error('签名验证异常:' . $e->getMessage());
            return false;
        }
    }

    /**
     * 构建签名
     *
     * 根据指定的签名类型和密钥为参数数组生成相应的签名
     *
     * @param array  $params   需要签名的参数数组
     * @param string $signType 签名类型
     * @param string $signKey  签名密钥，根据签名类型可能是字符串或私钥对象
     * @return array 签名参数数组（包含 'sign' 和 'sign_string' 两个键）
     * @throws Exception
     */
    public static function buildSignature(array $params, string $signType, string $signKey): array
    {
        try {
            $signString = self::buildSignString($params);

            // 根据签名类型使用对应的算法生成签名
            $sign = match ($signType) {
                'xxh' => hash('xxh128', $signString . $signKey),
                'sha3' => hash('sha3-256', $signString . $signKey),
                'sm3' => openssl_digest($signString . $signKey, 'sm3'),
                'rsa2' => RSA2::fromPrivateKey($signKey)->sign($signString),
                default => throw new Exception('不支持的签名类型')
            };
            return ['sign' => $sign, 'sign_string' => $signString];
        } catch (Throwable $e) {
            $msg = "[$signType]生成签名异常: " . $e->getMessage();
            Log::error($msg);
            throw new Exception($msg);
        }
    }
}
