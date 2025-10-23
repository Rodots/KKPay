<?php

declare(strict_types = 1);

namespace Core\Utils;

use support\Rodots\Crypto\RSA2;
use Throwable;

/**
 * 签名工具类
 * 提供统一的签名生成和验证方法
 */
final class SignatureUtil
{
    /**
     * 构建签名字符串（参考支付宝V3协议）
     * 格式：key=value键值对，英文逗号分隔，按字典序排序，空值或null忽略
     */
    public static function buildSignString(array $params, array $excludeKeys = ['sign']): string
    {
        $signParams = [];
        foreach ($params as $key => $value) {
            // 跳过排除的字段和空值
            if (in_array($key, $excludeKeys) || $value === '' || $value === null) {
                continue;
            }

            $signParams[$key] = $value;
        }

        // 按字典序排序
        ksort($signParams);

        // 构建签名字符串
        $signPairs = [];
        foreach ($signParams as $key => $value) {
            $signPairs[] = $key . '=' . $value;
        }

        return implode(',', $signPairs);
    }

    /**
     * 生成SHA3签名
     */
    public static function generateSha3Signature(string $signString, string $key): string
    {
        return hash('sha3-256', $signString . $key);
    }

    /**
     * 验证SHA3签名
     */
    public static function verifySha3Signature(string $signString, string $signature, string $key): bool
    {
        $expectedSignature = self::generateSha3Signature($signString, $key);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * 生成RSA2签名
     */
    public static function generateRsa2Signature(string $signString, string $privateKey): string
    {
        try {
            $rsa2 = RSA2::fromString($privateKey);
            return $rsa2->sign($signString);
        } catch (Throwable $e) {
            throw new \RuntimeException('RSA2签名生成失败: ' . $e->getMessage());
        }
    }

    /**
     * 验证RSA2签名
     */
    public static function verifyRsa2Signature(string $signString, string $signature, string $publicKey): bool
    {
        try {
            $rsa2 = RSA2::fromString('', $publicKey);
            return $rsa2->verify($signString, $signature);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 验证签名（自动识别签名类型）
     */
    public static function verifySignature(
        array   $params,
        string  $signType,
        ?string $sha3Key = null,
        ?string $rsa2PublicKey = null
    ): bool
    {
        $signString = self::buildSignString($params);
        $signature  = $params['sign'] ?? '';

        return match ($signType) {
            'sha3' => $sha3Key && self::verifySha3Signature($signString, $signature, $sha3Key),
            'rsa2' => $rsa2PublicKey && self::verifyRsa2Signature($signString, $signature, $rsa2PublicKey),
            default => false
        };
    }
}
