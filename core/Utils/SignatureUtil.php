<?php

declare(strict_types = 1);

namespace Core\Utils;

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
        $excludeKeys = ['sign', 'encryption_param'];

        $signParams = array_filter(
            $params,
            fn($value, $key) => !in_array($key, $excludeKeys) && $value !== '' && $value !== null,
            ARRAY_FILTER_USE_BOTH
        );

        ksort($signParams);

        return implode(',', array_map(
            fn($key, $value) => $key . '=' . $value,
            array_keys($signParams),
            $signParams
        ));
    }

    /**
     * 验证SHA3签名
     *
     * @param string      $signString 签名字符串
     * @param string      $signature  待验证签名
     * @param string|null $sha3Key    SHA3密钥
     * @return bool 验证结果
     */
    public static function verifySha3Signature(string $signString, string $signature, ?string $sha3Key): bool
    {
        if (empty($sha3Key)) {
            return false;
        }

        $expectedSignature = hash('sha3-256', $signString . $sha3Key);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * 验证RSA2签名
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
            $rsa2 = RSA2::fromString('', $publicKey);
            return $rsa2->verify($signString, $signature);
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
     * @param object $merchantEncryption 商户加密配置对象
     * @return bool 验证结果
     */
    public static function verifySignature(array $params, string $signType, object $merchantEncryption): bool
    {
        try {
            $signString = self::buildSignString($params);

            return match ($signType) {
                'sha3' => self::verifySha3Signature($signString, $params['sign'], $merchantEncryption->sha3_key),
                'rsa2' => self::verifyRsa2Signature($signString, $params['sign'], $merchantEncryption->rsa2_key),
                default => false
            };
        } catch (Throwable $e) {
            Log::error('签名验证异常:' . $e->getMessage());
            return false;
        }
    }
}
