<?php
declare(strict_types = 1);

namespace Core\Utils;

use Core\Exception\PaymentException;
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
     */
    private function __construct()
    {
    }

    /**
     * 清空缓存
     */
    public static function clearCache(): void
    {
        self::$infoCache = [];
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
        // 检查缓存
        if (!$force && isset(self::$infoCache[$gateway])) {
            return self::getFromCache($gateway, $key);
        }

        $info = self::readStaticInfo($gateway);
        if ($info === null) {
            return null;
        }

        // 缓存并返回
        self::$infoCache[$gateway] = $info;
        return self::getFromCache($gateway, $key);
    }

    /**
     * 加载支付网关类或调用类中的指定方法
     *
     * @param string $gateway 网关类名
     * @param string $method  要调用的方法名（可选）
     * @param array  $items   方法参数（可选）
     * @return string|array 返回完整类名或方法调用结果，如果类或方法不存在则抛出异常
     *
     * @throws PaymentException
     */
    public static function loadGateway(string $gateway, string $method = '', array $items = []): string|array
    {
        $fqcn = "\\Core\\Gateway\\$gateway\\$gateway";

        if (!class_exists($fqcn)) {
            throw new PaymentException("支付网关 '$gateway' 不存在。");
        }

        if ($method === '') {
            return $fqcn;
        }

        if (!method_exists($fqcn, $method)) {
            throw new PaymentException("支付网关 '$gateway' 中不存在方法 '$method'。");
        }

        return $fqcn::$method($items);
    }

    /**
     * 通过反射读取支付网关的描述
     *
     * @param string $gateway 网关类名
     * @return array|null 返回网关描述，如果读取失败则返回null
     */
    private static function readStaticInfo(string $gateway): ?array
    {
        try {
            $fqcn = self::loadGateway($gateway);

            $reflection = new ReflectionClass($fqcn);
            if (!$reflection->hasProperty('info')) {
                return null;
            }

            $property = $reflection->getProperty('info');
            return $property->isStatic() ? $property->getValue() : null;
        } catch (ReflectionException|PaymentException) {
            return null;
        }
    }

    /**
     * 从缓存中获取数据
     *
     * @param string $gateway 网关类名
     * @param string $key     要获取的特定信息键值
     * @return array|string|null
     */
    private static function getFromCache(string $gateway, string $key): array|string|null
    {
        if ($key === '' || !array_key_exists($key, self::$infoCache[$gateway])) {
            return self::$infoCache[$gateway];
        }

        return self::$infoCache[$gateway][$key];
    }
}
