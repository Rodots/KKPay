<?php

declare(strict_types = 1);

namespace support\Rodots\Functions;

use InvalidArgumentException;
use Random\RandomException;

/**
 * UUID 生成器类
 * 支持 UUID v4 (随机), v6 (时间排序), v7 (时间排序新格式)
 */
final readonly class Uuid
{
    private const int UUID_VERSION_4       = 4;
    private const int UUID_VERSION_6       = 6;
    private const int UUID_VERSION_7       = 7;
    private const int UUID_VARIANT_RFC4122 = 0b10;

    /**
     * 生成 UUID v4 (随机 UUID)
     *
     * @return non-empty-string
     * @throws RandomException
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        // 设置版本号 (4) 到第 13-16 位
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | (self::UUID_VERSION_4 << 4));

        // 设置变体 (RFC 4122) 到第 17-18 位
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | (self::UUID_VARIANT_RFC4122 << 6));

        return self::formatUuid($bytes);
    }

    /**
     * 生成 UUID v6 (时间排序 UUID)
     *
     * @param int|null    $timestamp 自定义时间戳 (微秒)，默认使用当前时间
     * @param string|null $nodeId    自定义节点 ID (6 字节)，默认使用随机数
     * @return non-empty-string
     * @throws RandomException
     * @throws InvalidArgumentException
     */
    public static function v6(?int $timestamp = null, ?string $nodeId = null): string
    {
        $timestamp ??= intval(microtime(true) * 1_000_000); // 微秒时间戳
        $nodeId    ??= substr(random_bytes(6), 0, 6); // 使用随机数作为节点 ID

        // 确保节点 ID 为 6 字节
        if ($nodeId !== null && strlen($nodeId) !== 6) {
            throw new InvalidArgumentException('Node ID must be exactly 6 bytes');
        }

        // 生成 60 位时间戳 (从 1582-10-15 开始计算)
        $unixTimestamp = $timestamp;
        $uuidTimestamp = $unixTimestamp + 12219292800000000; // 转换为 UUID 时间戳

        // 将时间戳转换为字节
        $timeBytes = pack('J', $uuidTimestamp);
        $timeBytes = substr($timeBytes, 2); // 取后 6 字节 + 2 字节

        // 生成时钟序列
        $clockSeq = random_bytes(2);
        $clockSeq = pack('n', unpack('n', $clockSeq)[1] & 0x3FFF); // 保留 14 位

        // 构造 UUID 字节
        $bytes = substr($timeBytes, 0, 6); // 时间戳前 6 字节
        $bytes .= chr((ord(substr($timeBytes, 6, 1)) & 0x0F) | (self::UUID_VERSION_6 << 4)); // 版本号
        $bytes .= chr((ord($clockSeq[0]) & 0x3F) | (self::UUID_VARIANT_RFC4122 << 6)); // 变体
        $bytes .= $clockSeq[1]; // 时钟序列低字节
        $bytes .= $nodeId; // 6 字节节点 ID

        return self::formatUuid($bytes);
    }

    /**
     * 生成 UUID v7 (时间排序 UUID 新格式)
     *
     * @param int|null $timestamp 自定义时间戳 (毫秒)，默认使用当前时间
     * @return non-empty-string
     * @throws RandomException
     */
    public static function v7(?int $timestamp = null): string
    {
        $timestamp ??= intval(microtime(true) * 1000);

        // 时间戳占前 48 位 (6 字节)
        $timeBytes = pack('J', $timestamp);
        $timeBytes = substr($timeBytes, 2, 6); // 取后 6 字节

        // 随机数占后 80 位 (10 字节)
        $randomBytes = random_bytes(10);

        $bytes = $timeBytes . $randomBytes;

        // 设置版本号 (7) 到第 49-52 位 (字节 6 的高 4 位)
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | (self::UUID_VERSION_7 << 4));

        // 设置变体 (RFC 4122) 到第 65-66 位 (字节 8 的高 2 位)
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | (self::UUID_VARIANT_RFC4122 << 6));

        return self::formatUuid($bytes);
    }

    /**
     * 格式化字节为 UUID 字符串格式
     *
     * @param string $bytes 16 字节的二进制数据
     * @return non-empty-string
     */
    private static function formatUuid(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),    // 8 位
            substr($hex, 8, 4),    // 4 位
            substr($hex, 12, 4),   // 4 位
            substr($hex, 16, 4),   // 4 位
            substr($hex, 20, 12)   // 12 位
        );
    }

    /**
     * 验证 UUID 格式是否有效
     *
     * @param string $uuid
     * @return bool
     */
    public static function isValid(string $uuid): bool
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $uuid) === 1;
    }

    /**
     * 获取 UUID 版本号
     *
     * @param string $uuid
     * @return int|null
     */
    public static function getVersion(string $uuid): ?int
    {
        if (!self::isValid($uuid)) {
            return null;
        }

        $versionChar = $uuid[14];
        return intval($versionChar);
    }
}
