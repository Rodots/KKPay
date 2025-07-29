<?php

declare(strict_types = 1);

/**
 * 生成指定长度和模式的随机字符串
 *
 * @param int    $length 要生成的字符串长度，默认为8
 * @param string $mode   生成模式
 *
 * @return string 生成的随机字符串
 */
function random(int $length = 8, string $mode = 'all'): string
{
    $chars = match ($mode) {
        'english' => 'abcdefghilkmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'num' => '0123456789',
        'lower' => 'abcdefghilkmnopqrstuvwxyz',
        'upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'lower_and_num' => 'abcdefghilkmnopqrstuvwxyz0123456789',
        'upper_and_num' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        default => 'abcdefghilkmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
    };
    return new Random\Randomizer()->getBytesFromString($chars, $length);
}

/**
 * 生成UUIDv4（通用唯一标识符，版本4）
 * UUIDv4是随机生成的UUID，其中版本4表示通过随机数生成
 * 此函数通过生成随机字节并按照UUIDv4的规范进行格式化，来生成一个UUIDv4
 *
 * @return string 生成的UUIDv4字符串
 * @throws Random\RandomException
 */
function generateUuidV4(): string
{
    // 生成16字节的随机字节
    $bytes = random_bytes(16);

    // 设置UUID版本（第7字节的高4位设置为0100）
    $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);

    // 设置UUID变体（第9字节的高4位设置为10）
    $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

    // 将字节转换为十六进制字符串
    $hex = bin2hex($bytes);

    // 格式化为UUID格式
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function filter(?string $string = null): string
{
    if ($string === null) {
        return '';
    }

    // 去除字符串两端空格（对防代码注入有一定作用）
    $string = trim($string);

    // 过滤html和php标签
    return strip_tags($string);
}

function html_filter(?string $string = null): string
{
    if ($string === null) {
        return '';
    }

    // 去除字符串两端空格（对防代码注入有一定作用）
    $string = trim($string);

    // 过滤html和php标签
    $string = strip_tags($string);

    // 特殊字符转实体
    return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
}

function is_https(): bool
{
    return (isset($_SERVER['HTTPS']) && (strtolower($_SERVER['HTTPS']) === 'on' || $_SERVER['HTTPS'] === true)) || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443) || (isset($_SERVER['HTTP_X_CLIENT_SCHEME']) && $_SERVER['HTTP_X_CLIENT_SCHEME'] === 'https') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') || (isset($_SERVER['HTTP_EWS_CUSTOME_SCHEME']) && $_SERVER['HTTP_EWS_CUSTOME_SCHEME'] === 'https');
}

function site_url(): string
{
    return (is_https() ? 'https://' : 'http://') . request()->host() . '/';
}

/**
 * 尝试将不同格式的日期时间字符串或Unix时间戳转换为统一的日期时间格式字符串。
 * @param string|int|null $datetime 输入值，可以是Unix时间戳整数或符合预设格式的日期时间字符串。
 * @return string 返回格式化后的日期时间字符串（格式为'Y-m-d H:i:s'），如果输入无法被识别，则返回错误信息。
 * @throws Exception
 */
function format_date(string|int|null $datetime = null): string
{
    $formats = [
        'Y-m-d\TH:i:s.u\Z', // ISO 8601格式，包含毫秒
        'Y-m-d\TH:i:s\Z',   // ISO 8601格式，不包含毫秒
        'Y-m-d H:i:s',      // 一般日期时间格式（不包含时区信息）
        '@U',              // Unix时间戳
    ];
    $date    = false;

    // 检查输入类型
    if (is_int($datetime)) {
        // 直接处理Unix时间戳
        $date = new DateTimeImmutable('@' . $datetime);
    } elseif (is_string($datetime)) {
        // 尝试所有预定义的格式来解析字符串
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $datetime);
            if ($date !== false) {
                break; // 成功解析，跳出循环
            }
        }
    } else {
        return "输入类型错误，请输入Unix时间戳或日期时间字符串。";
    }

    // 检查是否成功解析
    if ($date) {
        // 格式化输出
        return $date->format('Y-m-d H:i:s');
    } else {
        // 所有尝试的格式均未成功解析
        return "无法识别的日期时间格式。";
    }
}

/**
 * 获取客户端的IP地址
 *
 * @return string 客户端的IP地址，如果无法确定则返回'0.0.0.0'
 */
function get_client_ip(): string
{
    // 如站点使用了Cloudflare CDN，将尝试从请求头中获取'cf-connecting-ip'字段值，如果不存在则使用request()->getRemoteIp()获取IP地址
    // $ip = request()->header('cf-connecting-ip') ?: request()->getRemoteIp();

    // 返回获取到的IP地址，如果未获取到则返回'0.0.0.0'
    return request()->getRealIp();
}
