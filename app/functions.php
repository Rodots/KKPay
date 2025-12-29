<?php

declare(strict_types=1);

use app\model\Config;
use support\Cache;
use support\Log;

/**
 * 获取系统配置
 *
 * @param string      $group   配置组别，为null时获取所有组别
 * @param string|null $key     配置键名，为null时获取组别下所有配置
 * @param mixed       $default 默认值
 * @return mixed
 */
function sys_config(string $group = 'all', ?string $key = null, mixed $default = null): mixed
{
    // 构建缓存键名
    $cacheKey = 'sysconfig_' . $group;

    try {
        // 尝试从缓存获取
        $value = Cache::get($cacheKey);

        if ($value !== null) {
            // 如果缓存存在且不需要特定key，直接返回
            if ($key === null) {
                return $value;
            }

            // 如果需要特定key，检查缓存中是否存在
            if (isset($value[$key])) {
                return $value[$key];
            }
        }

        // 从数据库查询
        if ($group === null || $group === 'all') {
            // 获取所有配置
            $configs = Config::all()->groupBy('g')->map(function ($group) {
                return $group->pluck('v', 'k');
            })->toArray();
        } else {
            // 获取指定组别的配置
            $configs = Config::where('g', $group)->get()->pluck('v', 'k')->toArray();
        }

        // 缓存配置
        Cache::set($cacheKey, $configs, 3600);

        // 返回请求的配置
        if ($group === null) {
            return $configs;
        }

        return $key === null ? $configs : ($configs[$key] ?? $default);
    } catch (Throwable $e) {
        // 记录错误日志
        Log::error('系统配置项获取失败: ' . $e->getMessage());

        // 发生错误时返回默认值
        return $default;
    }
}

/**
 * 清除系统配置缓存
 *
 * @param string|null $group 配置组别，为null或'all'时清除所有分组缓存
 * @return bool
 */
function clear_sys_config_cache(?string $group = 'all'): bool
{
    try {
        if ($group === null || $group === 'all') {
            // 从数据库获取所有配置分组
            $groups = Config::query()->distinct()->pluck('g')->toArray();

            // 清除所有分组缓存
            foreach ($groups as $g) {
                Cache::delete('sysconfig_' . $g);
            }

            // 同时清除 'all' 缓存
            Cache::delete('sysconfig_all');
        } else {
            // 仅清除指定分组缓存
            Cache::delete('sysconfig_' . $group);
        }

        return true;
    } catch (Throwable $e) {
        // 记录错误日志
        Log::error('清除系统配置缓存失败: ' . $e->getMessage());
        return false;
    }
}

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

function site_url(?string $path = null): string
{
    return (is_https() ? 'https://' : 'http://') . request()->host() . '/' . ($path ?? '');
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

/**
 * 从URL中提取域名（包含端口，但忽略标准端口80/443）
 *
 * @param string $url 完整的URL地址
 * @return string|null 返回域名，失败时返回null
 */
function extract_domain(string $url): ?string
{
    // 验证URL格式
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    // 解析URL
    $parsedUrl = parse_url($url);

    if (!isset($parsedUrl['host'])) {
        return null;
    }

    $host   = $parsedUrl['host'];
    $scheme = $parsedUrl['scheme'] ?? 'http';
    $port   = $parsedUrl['port'] ?? null;

    // 如果没有端口，直接返回host
    if ($port === null) {
        return $host;
    }

    // 检查是否为标准端口
    $isStandardPort = ($scheme === 'https' && $port === 443) ||
        ($scheme === 'http' && $port === 80);

    // 如果是标准端口，忽略端口号
    if ($isStandardPort) {
        return $host;
    }

    // 非标准端口，返回host:port格式
    return $host . ':' . $port;
}

/**
 * 检测是否为移动设备（手机或平板）
 * @return bool
 */
function isMobile(): bool
{
    if (preg_match('/(blackberry|configuration\/cldc|hp |hp-|htc |htc_|htc-|iemobile|kindle|midp|mmp|motorola|mobile|nokia|opera mini|opera |Googlebot-Mobile|YahooSeeker\/M1A1-R2D2|android|iphone|ipod|mobi|palm|palmos|pocket|portalmmm|ppc;|smartphone|sonyericsson|sqh|spv|symbian|treo|up.browser|up.link|vodafone|windows ce|xda |xda_)/i', request()->header('user-agent', ''))) {
        return true;
    }
    return false;
}

/**
 * 检测是否为微信浏览器
 * @return bool
 */
function isWechat(): bool
{
    $userAgent = request()->header('user-agent', '');
    if (str_contains($userAgent, 'MicroMessenger/') && !str_contains($userAgent, 'WindowsWechat'))
        return true;
    else
        return false;
}

/**
 * 检测是否为支付宝
 * @return bool
 */
function isAlipay(): bool
{
    if (str_contains(request()->header('user-agent', ''), 'AlipayClient/'))
        return true;
    else
        return false;
}

/**
 * 检测是否为QQ
 * @return bool
 */
function isQQ(): bool
{
    if (str_contains(request()->header('user-agent', ''), 'QQ/'))
        return true;
    else
        return false;
}

/**
 * 检测是否为云闪付
 * @return bool
 */
function isUnionPay(): bool
{
    if (str_contains(request()->header('user-agent', ''), 'UnionPay/'))
        return true;
    else
        return false;
}
