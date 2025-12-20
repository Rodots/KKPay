<?php

declare(strict_types=1);

namespace support\Rodots\Functions;

use InvalidArgumentException;
use Random\RandomException;

/**
 * UUID 生成器类
 *
 * 支持以下 UUID 版本：
 * - v4：完全随机 UUID
 * - v7：时间排序 UUID（推荐，RFC 9562 标准）
 */
final readonly class Uuid
{
    // UUID 版本常量
    private const int VERSION_4 = 4;
    private const int VERSION_7 = 7;

    // RFC 4122 变体标识
    private const int VARIANT_RFC4122 = 0b10;

    /**
     * 生成 UUID v4（完全随机）
     *
     * @param bool $withDashes 是否包含横杠，默认为 false
     * @return non-empty-string 32 位（无横杠）或 36 位（有横杠）十六进制字符串
     * @throws RandomException
     */
    public static function v4(bool $withDashes = false): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | (self::VERSION_4 << 4));
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | (self::VARIANT_RFC4122 << 6));

        return self::formatUuid($bytes, $withDashes);
    }

    /**
     * 生成 UUID v7（时间排序，推荐使用）
     *
     * UUID v7 结构：
     * - 前 48 位：Unix 毫秒时间戳
     * - 4 位：版本号 (7)
     * - 12 位：随机数
     * - 2 位：变体标识
     * - 62 位：随机数
     *
     * @param int|null $timestamp  自定义时间戳（毫秒），默认使用当前时间
     * @param bool     $withDashes 是否包含横杠，默认为 false
     * @return non-empty-string
     * @throws RandomException
     */
    public static function v7(?int $timestamp = null, bool $withDashes = false): string
    {
        return self::formatUuid(self::v7Binary($timestamp), $withDashes);
    }

    /**
     * 生成 UUID v7 的二进制格式（适合直接存入 BINARY(16) 字段）
     *
     * @param int|null $timestamp 自定义时间戳（毫秒），默认使用当前时间
     * @return string 16 字节的二进制数据
     * @throws RandomException
     */
    public static function v7Binary(?int $timestamp = null): string
    {
        $timestamp ??= intval(microtime(true) * 1000);

        // 时间戳占前 48 位（6 字节）
        $timeBytes = substr(pack('J', $timestamp), 2, 6);

        // 随机数占后 80 位（10 字节）
        $randomBytes = random_bytes(10);

        $bytes = $timeBytes . $randomBytes;

        // 设置版本号 (7) 到字节 6 的高 4 位
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | (self::VERSION_7 << 4));

        // 设置变体 (RFC 4122) 到字节 8 的高 2 位
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | (self::VARIANT_RFC4122 << 6));

        return $bytes;
    }

    /**
     * 将十六进制 UUID 字符串转换为二进制格式
     *
     * @param string $hex 32 位十六进制字符串（不含横杠）或 36 位（含横杠）
     * @return string 16 字节的二进制数据
     * @throws InvalidArgumentException
     */
    public static function toBinary(string $hex): string
    {
        $hex = str_replace('-', '', $hex);

        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            throw new InvalidArgumentException('无效的 UUID 十六进制格式，必须是 32 位十六进制字符串');
        }

        return hex2bin($hex);
    }

    /**
     * 将二进制 UUID 转换为十六进制字符串
     *
     * @param string $binary     16 字节的二进制数据
     * @param bool   $withDashes 是否包含横杠，默认为 false
     * @return non-empty-string 32 或 36 位十六进制字符串
     * @throws InvalidArgumentException
     */
    public static function toHex(string $binary, bool $withDashes = false): string
    {
        if (strlen($binary) !== 16) {
            throw new InvalidArgumentException('无效的二进制 UUID，必须是 16 字节');
        }

        return self::formatUuid($binary, $withDashes);
    }

    /**
     * 验证 UUID 格式是否有效
     *
     * 支持以下格式：
     * - 带横杠：xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx（36 位）
     * - 不带横杠：xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx（32 位）
     *
     * @param string $uuid UUID 字符串
     * @return bool
     */
    public static function isValid(string $uuid): bool
    {
        $length = strlen($uuid);

        if ($length === 36) {
            return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
        }

        if ($length === 32) {
            return ctype_xdigit($uuid);
        }

        return false;
    }

    /**
     * 格式化字节为 UUID 字符串格式
     *
     * @param string $bytes      16 字节的二进制数据
     * @param bool   $withDashes 是否包含横杠
     * @return non-empty-string
     */
    private static function formatUuid(string $bytes, bool $withDashes = true): string
    {
        $hex = bin2hex($bytes);

        if (!$withDashes) {
            return $hex;
        }

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
