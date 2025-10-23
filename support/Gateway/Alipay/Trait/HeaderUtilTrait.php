<?php

declare(strict_types = 1);

namespace Gateway\Alipay\Trait;

/**
 * HTTP 头部处理辅助特征
 */
trait HeaderUtilTrait
{
    /**
     * 从头数组中获取指定键的首个值
     */
    protected function getHeaderValue(array $headers, string $key): string
    {
        if (!array_key_exists($key, $headers)) {
            return '';
        }

        $value = $headers[$key];
        return is_array($value) ? ($value[0] ?? '') : (string)$value;
    }

    /**
     * 批量获取头部值
     */
    protected function getHeaderValues(array $headers, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getHeaderValue($headers, $key);
        }
        return $result;
    }
}
