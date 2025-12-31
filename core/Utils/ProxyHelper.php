<?php

declare(strict_types=1);

namespace Core\Utils;

/**
 * 代理配置帮助类
 * 用于构建Guzzle HTTP客户端的代理配置
 */
class ProxyHelper
{
    /**
     * 获取Guzzle代理配置
     *
     * @return array Guzzle配置数组（包含proxy键，如禁用则为空数组）
     */
    public static function getGuzzleProxyConfig(): array
    {
        // 一次性获取整个 proxy 配置组
        $proxyConfig = sys_config('proxy');

        if (($proxyConfig['proxy_switch'] ?? 'off') !== 'on') {
            return [];
        }

        $host     = $proxyConfig['proxy_host'] ?? '';
        $port     = $proxyConfig['proxy_port'] ?? '';
        $protocol = $proxyConfig['proxy_protocol'] ?? 'http';
        $user     = $proxyConfig['proxy_user'] ?? '';
        $password = $proxyConfig['proxy_password'] ?? '';

        if (empty($host) || empty($port)) {
            return [];
        }

        // 构建代理URL
        $proxyUrl = $protocol . '://';
        if (!empty($user) && !empty($password)) {
            $proxyUrl .= urlencode($user) . ':' . urlencode($password) . '@';
        }
        $proxyUrl .= $host . ':' . $port;

        return ['proxy' => $proxyUrl];
    }
}
