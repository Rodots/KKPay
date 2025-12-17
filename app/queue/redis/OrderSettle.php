<?php

declare(strict_types = 1);

namespace app\queue\redis;

use app\model\Merchant;
use app\model\MerchantWalletRecord;
use app\model\Order;
use Exception;
use support\Db;
use support\Log;
use Throwable;
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
     * @param string $data 平台订单号
     * @return void
     * @throws Exception
     */
    public function consume($data): void
    {
        Db::beginTransaction();
        try {
            // 使用悲观锁获取订单，确保并发安全，防止重复结算
            $order = Order::where('trade_no', $data)->lockForUpdate()->first(['trade_no', 'merchant_id', 'receipt_amount', 'trade_state', 'settle_state']);

            // 订单不存在或状态不符合结算要求（非处理中），直接回滚
            if (!$order || $order->settle_state !== Order::SETTLE_STATE_PROCESSING) {
                Db::rollBack();
                return;
            }

            // 判断是否满足结算条件：非冻结状态 且 商户拥有结算权限
            if ($order->trade_state !== Order::TRADE_STATE_FROZEN && Merchant::where('id', $order->merchant_id)->whereJsonContains('competence', 'settle')->exists()) {
                // 执行结算：变更钱包余额（增加可用余额，同时扣除冻结/不可用余额）
                MerchantWalletRecord::changeAvailable($order->merchant_id, $order->receipt_amount, '订单收益', true, $order->trade_no, '自动结算(延迟)', true);
                $order->settle_state = Order::SETTLE_STATE_COMPLETED;
            } else {
                // 不满足条件（被冻结或无权限），标记为结算失败
                $order->settle_state = Order::SETTLE_STATE_FAILED;
            }
            $order->save();
            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            Log::error('结算队列执行失败：' . $e->getMessage());
        }
    }
}
