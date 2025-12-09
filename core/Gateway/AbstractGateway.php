<?php

declare(strict_types = 1);

namespace Core\Gateway;

use Core\Service\OrderService;
use support\Db;
use Throwable;

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
            DB::table('order')->where('trade_no', $trade_no)->update(['payment_ext' => serialize($newExt)]);

            // 5. 返回新数据
            return $newExt;
        });
    }

    /**
     * 处理支付异步通知
     * 调用OrderService处理支付成功逻辑
     *
     * @param string          $trade_no      平台订单号
     * @param string|int|null $api_trade_no  上游订单号
     * @param string|int|null $bill_trade_no 真实交易流水号
     * @param string|int|null $mch_trade_no  渠道交易流水号
     * @param string|int|null $payment_time  支付时间
     * @param array           $buyer         买家信息
     * @return void
     * @throws Throwable
     */
    protected static function processNotify(string $trade_no, string|int|null $api_trade_no = null, string|int|null $bill_trade_no = null, string|int|null $mch_trade_no = null, string|int|null $payment_time = null, array $buyer = []): void
    {
        OrderService::handlePaymentSuccess(true, $trade_no, $api_trade_no, $bill_trade_no, $mch_trade_no, $payment_time, $buyer);
    }

    /**
     * 处理支付同步通知
     * 调用OrderService处理支付成功逻辑
     *
     * @param string          $trade_no      平台订单号
     * @param string|int|null $api_trade_no  上游订单号
     * @param string|int|null $bill_trade_no 真实交易流水号
     * @param string|int|null $mch_trade_no  渠道交易流水号
     * @param string|int|null $payment_time  支付时间
     * @param array           $buyer         买家信息
     * @return void
     * @throws Throwable
     */
    protected static function processReturn(string $trade_no, string|int|null $api_trade_no = null, string|int|null $bill_trade_no = null, string|int|null $mch_trade_no = null, string|int|null $payment_time = null, array $buyer = []): void
    {
        OrderService::handlePaymentSuccess(false, $trade_no, $api_trade_no, $bill_trade_no, $mch_trade_no, $payment_time, $buyer);
    }
}
