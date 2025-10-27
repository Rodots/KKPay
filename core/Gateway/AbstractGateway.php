<?php

declare(strict_types = 1);

namespace Core\Gateway;

use support\Db;

/**
 * 抽象网关基类
 * 提供静态方法架构和扩展支持
 */
abstract class AbstractGateway
{
    /**
     * 网关信息
     */
    public static array $info = [
        'title'       => '',
        'author'      => '',
        'url'         => '',
        'description' => '',
        'version'     => '1.0.0',
        'notes'       => '',
        'config'      => []
    ];

    /**
     * 验证配置
     */
    protected static function validateConfig(array $config): bool
    {
        if (empty($config)) {
            return false;
        }

        // 子类可以重写此方法进行具体的配置验证
        return true;
    }

    /**
     * 核心支付方法 - 必须实现
     */
    abstract public static function submit(array $items): array;

    /**
     * 核心通知方法 - 必须实现
     */
    abstract public static function notify(array $items): array;

    /**
     * 核心退款方法 - 必须实现
     */
    abstract public static function refund(array $items): array;

    /**
     * 加锁设置订单扩展数据
     *
     * @param string   $trade_no 订单号
     * @param callable $func     用于生成扩展数据的闭包
     * @return mixed 返回扩展数据
     */
    protected static function lockPaymentExt(string $trade_no, callable $func): mixed
    {
        return DB::transaction(function () use ($trade_no, $func) {
            // 1. 使用 DB::table 直接查询并进行排他锁
            $ext = DB::table('order')->where('trade_no', $trade_no)->lockForUpdate()->value('payment_ext');

            // 2. 检查 ext 字段是否已有数据，如有则解析并返回
            if ($ext !== null) {
                return unserialize($ext);
            }

            // 3. 执行闭包获取新数据
            $newExt = $func();

            // 4. 直接使用 update 方法更新数据库
            // 使用 serialize 将新数据序列化为字符串
            DB::table($tableName)->where('trade_no', $trade_no)->update(['payment_ext' => serialize($newExt)]);

            // 5. 返回新数据
            return $newExt;
        });
    }

    /**
     * 格式化金额（元转分）
     */
    protected static function formatAmount(float $amount): int
    {
        return (int)round($amount * 100);
    }

    /**
     * 格式化金额（分转元）
     */
    protected static function formatAmountToYuan(int $amount): float
    {
        return round($amount / 100, 2);
    }
}
