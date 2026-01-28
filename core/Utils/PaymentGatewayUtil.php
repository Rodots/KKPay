<?php

declare(strict_types=1);

namespace Core\Utils;

use Core\Exception\PaymentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * 支付网关工具类
 */
final class PaymentGatewayUtil
{
    /** @var array<string, array> 缓存存储，避免重复读取反射 */
    private static array $infoCache = [];

    private function __construct() { }

    /**
     * 清空缓存
     */
    public static function clearCache(): void
    {
        self::$infoCache = [];
    }

    /**
     * 构建网关完全限定类名
     */
    private static function buildFqcn(string $gateway): string
    {
        return "\\Core\\Gateway\\$gateway\\$gateway";
    }

    /**
     * 验证网关方法是否为公共静态方法
     *
     * @throws PaymentException
     */
    private static function validateStaticMethod(string $fqcn, string $method): ReflectionMethod
    {
        if (!method_exists($fqcn, $method)) {
            throw new PaymentException("支付网关中不存在方法 '$method'。");
        }

        $reflection = new ReflectionMethod($fqcn, $method);
        if (!$reflection->isPublic() || !$reflection->isStatic()) {
            throw new PaymentException("支付网关中不存在方法 '$method'。");
        }

        return $reflection;
    }

    /**
     * 加载支付网关类或调用类中的指定方法
     *
     * @param string $gateway 网关类名
     * @param string $method  要调用的方法名（可选）
     * @param array  $items   方法参数（可选）
     * @return string|array 返回完整类名或方法调用结果
     *
     * @throws PaymentException
     */
    public static function loadGateway(string $gateway, string $method = '', array $items = []): string|array
    {
        $fqcn = self::buildFqcn($gateway);

        if (!class_exists($fqcn)) {
            throw new PaymentException("支付网关 '$gateway' 不存在。");
        }

        if ($method === '') {
            return $fqcn;
        }

        self::validateStaticMethod($fqcn, $method);
        return $fqcn::$method($items);
    }

    /**
     * 加载支付网关并动态展开参数调用方法
     *
     * 与 loadGateway 的区别：
     * - loadGateway: 调用 $fqcn::$method($items) - 将整个数组作为单个参数传递
     * - loadGatewayWithSpread: 调用 $fqcn::$method($order, $channel, ...) - 将数组的值展开为独立参数
     *
     * @param string $gateway 网关类名
     * @param string $method  要调用的方法名
     * @param array  $items   方法参数，键名对应方法参数名，键值为参数值
     * @return mixed 方法调用结果
     *
     * @throws PaymentException
     */
    public static function loadGatewayWithSpread(string $gateway, string $method, array $items = []): mixed
    {
        $fqcn = self::buildFqcn($gateway);

        if (!class_exists($fqcn)) {
            throw new PaymentException("支付网关 '$gateway' 不存在。");
        }

        $reflection = self::validateStaticMethod($fqcn, $method);

        // 根据方法参数顺序构建参数数组
        $args = [];
        foreach ($reflection->getParameters() as $param) {
            $paramName = $param->getName();
            if (array_key_exists($paramName, $items)) {
                $args[] = $items[$paramName];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new PaymentException("缺少必需参数 '$paramName'。");
            }
        }

        return $fqcn::$method(...$args);
    }

    /**
     * 检查支付网关中是否存在指定方法
     *
     * @param string $gateway 网关名称
     * @param string $method  方法名称
     * @return bool 当网关类和方法都存在时返回 false，否则返回 true
     */
    public static function existMethod(string $gateway, string $method): bool
    {
        $fqcn = self::buildFqcn($gateway);
        return !(class_exists($fqcn, false) && method_exists($fqcn, $method));
    }

    /**
     * 从缓存中获取数据
     */
    private static function getFromCache(string $gateway, string $key): array|string|null
    {
        return $key !== '' && array_key_exists($key, self::$infoCache[$gateway])
            ? self::$infoCache[$gateway][$key]
            : self::$infoCache[$gateway];
    }

    /**
     * 通过反射读取支付网关的描述
     */
    private static function readStaticInfo(string $gateway): ?array
    {
        try {
            $fqcn       = self::loadGateway($gateway);
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
     * 获取支付网关的完整描述
     *
     * @param string $gateway 网关类名
     * @param string $key     要获取的特定信息键值（可选）
     * @param bool   $force   是否强制刷新缓存
     * @return array|string|null 返回网关描述数组或特定信息，如果不存在则返回 null
     */
    public static function getInfo(string $gateway, string $key = '', bool $force = false): array|string|null
    {
        if (!$force && isset(self::$infoCache[$gateway])) {
            return self::getFromCache($gateway, $key);
        }

        $info = self::readStaticInfo($gateway);
        if ($info === null) {
            return null;
        }

        self::$infoCache[$gateway] = $info;
        return self::getFromCache($gateway, $key);
    }
}
