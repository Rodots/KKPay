<?php

declare(strict_types = 1);

namespace app\queue\redis;

use app\model\MerchantWalletRecord;
use app\model\Order;
use Exception;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis as SyncQueue;

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
     * @param string $data 平台订单号
     * @return void
     * @throws Exception
     */
    public function consume($data): void
    {
        $order = Order::where('trade_no', $data)->first(['trade_no', 'merchant_id', 'receipt_amount', 'trade_state', 'settle_state']);
        if (!$order) {
            return;
        }

        // 检查订单结算状态，避免重复处理
        if ($order->settle_state !== Order::SETTLE_STATE_PROCESSING) {
            return;
        }
        // 当订单交易状态为冻结时，将结算状态直接标记为失败
        if ($order->trade_state === Order::TRADE_STATE_FROZEN) {
            $order->settle_state = Order::SETTLE_STATE_FAILED;
            $order->save();
        }

        // 执行商户钱包金额变更操作
        MerchantWalletRecord::changeAvailable($order->merchant_id, $order->receipt_amount, '订单收益', true, $order->trade_no, '自动结算(延迟)', true);
        $order->settle_state = Order::SETTLE_STATE_COMPLETED;
        $order->save();
    }
}
