<?php

declare(strict_types=1);

namespace app\model\casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use support\Rodots\Functions\Uuid;

/**
 * 二进制 UUID 类型转换器
 *
 * 用于在 Eloquent 模型中自动处理 BINARY(16) UUID 字段的读写转换：
 * - 从数据库读取时：将 16 字节二进制数据转换为 32 位十六进制字符串
 * - 写入数据库时：将十六进制字符串（32 位或 36 位带横杠）转换为 16 字节二进制数据
 */
class BinaryUuidCast implements CastsAttributes
{
    /**
     * 从数据库读取时转换为十六进制字符串
     *
     * @param Model  $model      模型实例
     * @param string $key        属性名
     * @param mixed  $value      原始值（二进制）
     * @param array  $attributes 所有属性
     * @return string|null 32 位十六进制字符串
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // 如果已经是十六进制字符串（32位），直接返回
        if (is_string($value) && strlen($value) === 32 && ctype_xdigit($value)) {
            return $value;
        }

        // 二进制转十六进制
        return Uuid::toHex($value);
    }

    /**
     * 写入数据库前转换为二进制格式
     *
     * @param Model  $model      模型实例
     * @param string $key        属性名
     * @param mixed  $value      输入值（十六进制字符串）
     * @param array  $attributes 所有属性
     * @return string|null 16 字节二进制数据
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // 如果已经是 16 字节二进制，直接返回
        if (strlen($value) === 16) {
            return $value;
        }

        // 十六进制转二进制
        return Uuid::toBinary($value);
    }
}
