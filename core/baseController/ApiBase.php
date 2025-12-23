<?php

declare(strict_types=1);

namespace Core\baseController;

use app\api\v1\middleware\ApiSignatureVerification;
use Core\Traits\ApiResponse;
use support\annotation\Middleware;
use support\Request;

/**
 * API v1 基类控制器
 *
 * 为所有商户 API 控制器提供统一的功能：
 * - API 签名验证中间件
 * - 响应格式化方法 (success, fail, error)
 * - 业务参数解析方法 (parseBizContent, getString, getAmount, getInt)
 * - 商户上下文获取 (getMerchantId, getMerchantNumber)
 */
#[Middleware(ApiSignatureVerification::class)]
class ApiBase
{
    use ApiResponse;

    /**
     * 解析业务参数
     *
     * 从中间件验证后的 verifiedParams 中提取 biz_content，
     * 进行 Base64 解码和 JSON 解析
     *
     * @param Request $request 请求对象（由中间件注入 verifiedParams）
     * @return array|string 解析后的业务参数数组，或错误消息字符串
     */
    protected function parseBizContent(Request $request): array|string
    {
        $content = $request->verifiedParams['biz_content'] ?? null;
        if (!$content) {
            return '业务参数(biz_content)缺失';
        }

        $decoded = base64_decode($content, true);
        if ($decoded === false) {
            return '业务参数base64解码失败';
        }

        if (!json_validate($decoded)) {
            return '业务参数非JSON格式';
        }

        return json_decode($decoded, true);
    }

    /**
     * 获取字符串参数
     *
     * 规则：
     * 1. 键不存在返回 null
     * 2. 值非标量(如数组)返回 null
     * 3. 值为 null 返回 null
     * 4. 字符串自动 trim
     * 5. 空字符串根据 $allowEmpty 决定是否返回 null
     *
     * @param array $data 数据源
     * @param string $key 键名
     * @param bool $allowEmpty 是否允许空字符串（false时空字符串转null）
     * @return string|null
     */
    protected function getString(array $data, string $key, bool $allowEmpty = false): ?string
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];

        // 严格排除数组/对象等非标量
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $str = trim((string)$value);

        if ($str === '' && !$allowEmpty) {
            return null;
        }

        return $str;
    }

    /**
     * 获取金额参数
     *
     * 规则：
     * 1. 自动转为字符串格式
     * 2. 非数字或空值返回默认值
     *
     * @param array $data 数据源
     * @param string $key 键名
     * @param string $default 默认值
     * @return string
     */
    protected function getAmount(array $data, string $key, string $default = '0'): string
    {
        if (!isset($data[$key])) {
            return $default;
        }

        $value = $data[$key];

        if (!is_scalar($value)) {
            return $default;
        }

        // 移除可能存在的千分位逗号等字符，确保是纯数字
        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
        }

        return is_numeric($value) ? (string)$value : $default;
    }

    /**
     * 获取整数参数
     *
     * @param array $data 数据源
     * @param string $key 键名
     * @param int $default 默认值
     * @return int
     */
    protected function getInt(array $data, string $key, int $default = 0): int
    {
        if (!isset($data[$key])) {
            return $default;
        }

        $value = $data[$key];
        if (!is_scalar($value)) {
            return $default;
        }

        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * 获取当前商户ID
     *
     * @param Request $request 请求对象
     * @return int 商户ID
     */
    protected function getMerchantId(Request $request): int
    {
        return $request->merchant->id;
    }

    /**
     * 获取当前商户编号
     *
     * @param Request $request 请求对象
     * @return string 商户编号
     */
    protected function getMerchantNumber(Request $request): string
    {
        return $request->merchant->merchant_number;
    }
}
