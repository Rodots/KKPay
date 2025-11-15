<?php

declare(strict_types = 1);

namespace app\queue\redis;

use app\model\MerchantWalletRecord;
use app\model\Order;
use Webman\RedisQueue\Consumer;

class OrderSettle implements Consumer
{
    public string $queue      = 'order-settle';
    public string $connection = 'default';

    /**
     * 处理订单结算消息队列数据
     *
     * 该方法用于消费订单结算队列中的消息，对符合条件的订单执行结算操作。
     * 只有当订单存在且未处于完成或待处理状态时才会执行结算。
     *
     * @param array $data 包含平台订单号信息的数据数组
     * @return void
     */
    public function consume($data): void
    {
        // 检查平台订单号是否存在
        if (!isset($data['trade_no'])) {
            return;
        }

        // 查询订单信息
        $order = Order::select(['trade_no', 'merchant_id', 'receipt_amount', 'settle_state'])->where('trade_no', $data['trade_no'])->first();
        if (!$order) {
            return;
        }

        // 检查订单结算状态，避免重复处理
        if ($order->settle_state === Order::SETTLE_STATE_COMPLETED || $order->settle_state === Order::SETTLE_STATE_PENDING) {
            return;
        }

        // 执行商户钱包金额变更操作
        if (MerchantWalletRecord::change($order->merchant_id, $order->receipt_amount, '订单收益', true, $order->trade_no, '系统自动结算(延迟)')) {
            $order->settle_state = Order::SETTLE_STATE_COMPLETED;
        } else {
            $order->settle_state = Order::SETTLE_STATE_FAILED;
        }
        $order->save();
    }
}
