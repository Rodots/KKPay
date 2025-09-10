<?php
declare(strict_types = 1);

namespace Core\Utils;

use ReflectionClass;
use ReflectionException;

/**
 * 支付网关工具类
 */
final class PaymentGatewayUtil
{
    /**
     * 缓存存储，避免重复读取反射
     *
     * @var array<string, array>
     */
    private static array $infoCache = [];

    /**
     * 私有构造函数，防止实例化
     *
     * 由于该类只提供静态方法，因此不需要实例化
     */
    private function __construct()
    {
    }

    /**
     * 清空缓存
     *
     * @return true
     */
    public static function clearCache(): true
    {
        self::$infoCache = [];

        return true;
    }

    /**
     * 获取支付网关的完整描述
     *
     * @param string $gateway 网关类名
     * @param string $key     要获取的特定信息键值（可选）
     * @param bool   $force   是否强制刷新缓存
     * @return array|string|null 返回网关描述数组或特定信息，如果不存在则返回null
     */
    public static function getInfo(string $gateway, string $key = '', bool $force = false): array|string|null
    {
        // 使用null合并赋值操作符的变体逻辑
        $hasKey = $key !== '';

        // 检查缓存
        if (!$force && array_key_exists($gateway, self::$infoCache)) {
            $cachedInfo = self::$infoCache[$gateway];
            if ($hasKey) {
                return array_key_exists($key, $cachedInfo) ? $cachedInfo[$key] : $cachedInfo;
            }
            return $cachedInfo;
        }

        $info = self::readStaticInfo($gateway);

        if ($info === null) {
            return null;
        }

        // 缓存并返回
        self::$infoCache[$gateway] = $info;

        if ($hasKey) {
            return array_key_exists($key, $info) ? $info[$key] : $info;
        }

        return $info;
    }

    /**
     * 通过反射读取支付网关的描述
     *
     * @param string $gateway 网关类名
     * @return array|null 返回网关描述，如果读取失败则返回null
     *
     */
    private static function readStaticInfo(string $gateway): ?array
    {
        // 构造完整的类名路径
        $fqcn = "\\Core\\Gateway\\$gateway\\$gateway";

        try {
            $reflection = new ReflectionClass($fqcn);
        } catch (ReflectionException) {
            return null;
        }

        // 检查是否存在info属性
        if (!$reflection->hasProperty('info')) {
            return null;
        }

        $prop = $reflection->getProperty('info');

        // 只返回静态属性的值
        return $prop->isStatic() ? $prop->getValue() : null;
    }
}
