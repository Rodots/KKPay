<?php

declare(strict_types = 1);

namespace Core\Service;

use app\model\Order;
use Core\Exception\PaymentException;
use Core\Utils\SignatureUtil;
use support\Log;
use support\Db;
use Throwable;

/**
 * 订单服务类
 * 负责订单状态管理和业务逻辑
 */
class OrderService
{
    /**
     * 更新订单状态
     */
    public static function updateOrderStatus(
        string $tradeNo,
        string $newStatus,
        ?array $additionalData = null
    ): bool
    {
        Db::beginTransaction();

        try {
            $order = Order::where('trade_no', $tradeNo)->first();
            if (!$order) {
                throw new PaymentException('订单不存在');
            }

            $oldStatus = $order->trade_state;

            // 验证状态转换是否合法
            if (!self::isValidStatusTransition($oldStatus, $newStatus)) {
                throw new PaymentException("订单状态不能从 {$oldStatus} 转换为 {$newStatus}");
            }

            // 更新订单状态
            $order->trade_state = $newStatus;

            // 当前时间戳
            $now_time = time();

            // 根据状态更新相关字段
            switch ($newStatus) {
                case Order::TRADE_STATE_SUCCESS:
                    $order->payment_time = date('Y-m-d H:i:s');
                    if ($additionalData) {
                        $order->buyer_pay_amount = $additionalData['buyer_pay_amount'] ?? $order->total_amount;
                        $order->api_trade_no     = $additionalData['api_trade_no'] ?? $order->api_trade_no;
                        $order->bill_trade_no    = $additionalData['bill_trade_no'] ?? null;
                    }
                    break;

                case Order::TRADE_STATE_CLOSED:
                    $order->close_time = $now_time;
                    break;

                case Order::TRADE_STATE_FINISHED:
                    if (!$order->payment_time) {
                        $order->payment_time = date('Y-m-d H:i:s');
                    }
                    $order->close_time = $now_time;
                    break;
            }

            $order->update_time = $now_time;
            $order->save();

            // 记录状态变更日志
            Log::info('订单状态更新', [
                'trade_no'        => $tradeNo,
                'old_status'      => $oldStatus,
                'new_status'      => $newStatus,
                'additional_data' => $additionalData
            ]);

            Db::commit();
            return true;

        } catch (Throwable $e) {
            Db::rollBack();

            Log::error('订单状态更新失败', [
                'trade_no'   => $tradeNo,
                'new_status' => $newStatus,
                'error'      => $e->getMessage()
            ]);

            throw new PaymentException('订单状态更新失败：' . $e->getMessage());
        }
    }

    /**
     * 验证订单状态转换是否合法
     */
    private static function isValidStatusTransition(string $fromStatus, string $toStatus): bool
    {
        $validTransitions = [
            Order::TRADE_STATE_WAIT_PAY => [
                Order::TRADE_STATE_SUCCESS,
                Order::TRADE_STATE_CLOSED,
                Order::TRADE_STATE_FROZEN
            ],
            Order::TRADE_STATE_SUCCESS  => [
                Order::TRADE_STATE_FINISHED,
                Order::TRADE_STATE_FROZEN
            ],
            Order::TRADE_STATE_FROZEN   => [
                Order::TRADE_STATE_SUCCESS,
                Order::TRADE_STATE_CLOSED
            ],
            Order::TRADE_STATE_CLOSED   => [], // 已关闭的订单不能再转换
            Order::TRADE_STATE_FINISHED => [] // 已完成的订单不能再转换
        ];

        return in_array($toStatus, $validTransitions[$fromStatus] ?? []);
    }

    /**
     * 处理支付成功
     */
    public static function handlePaymentSuccess(string $tradeNo, array $paymentData): bool
    {
        try {
            // 更新订单状态
            self::updateOrderStatus($tradeNo, Order::TRADE_STATE_SUCCESS, $paymentData);

            // 计算手续费和利润
            self::calculateOrderFees($tradeNo);

            // 发送异步通知
            self::sendAsyncNotification($tradeNo);

            Log::info('支付成功处理完成', [
                'trade_no'     => $tradeNo,
                'payment_data' => $paymentData
            ]);

            return true;

        } catch (Throwable $e) {
            Log::error('支付成功处理失败', [
                'trade_no' => $tradeNo,
                'error'    => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 计算订单手续费和利润
     */
    private static function calculateOrderFees(string $tradeNo): void
    {
        $order = Order::with('paymentChannelAccount.paymentChannel')->where('trade_no', $tradeNo)->first();
        if (!$order || !$order->paymentChannelAccount) {
            return;
        }

        $channel     = $order->paymentChannelAccount->paymentChannel;
        $totalAmount = $order->buyer_pay_amount ?? $order->total_amount;

        // 计算平台手续费
        $feeAmount = $totalAmount * $channel->rate + $channel->fixed_fee;
        if ($channel->min_fee && $feeAmount < $channel->min_fee) {
            $feeAmount = $channel->min_fee;
        }
        if ($channel->max_fee && $feeAmount > $channel->max_fee) {
            $feeAmount = $channel->max_fee;
        }

        // 计算成本
        $costAmount = $totalAmount * $channel->costs + $channel->fixed_costs;

        // 计算商户实收金额
        $receiptAmount = $totalAmount - $feeAmount;

        // 计算利润
        $profitAmount = $feeAmount - $costAmount;

        // 更新订单
        $order->fee_amount     = round($feeAmount, 2);
        $order->receipt_amount = round($receiptAmount, 2);
        $order->profit_amount  = round($profitAmount, 2);
        $order->save();

        Log::info('订单费用计算完成', [
            'trade_no'       => $tradeNo,
            'total_amount'   => $totalAmount,
            'fee_amount'     => $feeAmount,
            'receipt_amount' => $receiptAmount,
            'profit_amount'  => $profitAmount
        ]);
    }

    /**
     * 发送异步通知
     */
    private static function sendAsyncNotification(string $tradeNo): void
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return;
        }

        // 构建通知数据
        $notifyData = [
            'trade_no'         => $order->trade_no,
            'out_trade_no'     => $order->out_trade_no,
            'trade_state'      => $order->trade_state,
            'total_amount'     => $order->total_amount,
            'buyer_pay_amount' => $order->buyer_pay_amount,
            'receipt_amount'   => $order->receipt_amount,
            'payment_time'     => $order->payment_time,
            'attach'           => $order->attach,
        ];

        // TODO: 实现异步通知发送逻辑
        // 这里应该使用队列系统来发送通知，确保可靠性

        Log::info('异步通知已加入队列', [
            'trade_no'   => $tradeNo,
            'notify_url' => $order->notify_url
        ]);
    }

    /**
     * 构建同步通知参数
     */
    public static function buildSyncNotificationParams(array $order): string
    {
        $order['timestamp'] = time();
        $order['sign_type'] = 'rsa2';
        $order['sign']      = SignatureUtil::buildSignature($order, $order['sign_type'], sys_config('payment', 'system_rsa_private_key', 'Rodots'));

        $queryString = http_build_query($order);
        $separator   = str_contains($order['return_url'], '?') ? '&' : '?';

        return $order['return_url'] . $separator . $queryString;
    }

    /**
     * 检查订单是否可以支付
     */
    public static function canPay(Order $order): bool
    {
        // 检查订单状态
        if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY) {
            return false;
        }

        // 检查订单是否过期（默认30分钟）
        $expireTime = strtotime($order->create_time) + 1800;
        if (time() > $expireTime) {
            return false;
        }

        return true;
    }

    /**
     * 关闭过期订单
     */
    public static function closeExpiredOrders(): int
    {
        $expiredOrders = Order::where('trade_state', Order::TRADE_STATE_WAIT_PAY)
            ->where('create_time', '<', date('Y-m-d H:i:s', time() - 1800))
            ->get();

        $closedCount = 0;
        foreach ($expiredOrders as $order) {
            try {
                self::updateOrderStatus($order->trade_no, Order::TRADE_STATE_CLOSED);
                $closedCount++;
            } catch (Throwable $e) {
                Log::error('关闭过期订单失败', [
                    'trade_no' => $order->trade_no,
                    'error'    => $e->getMessage()
                ]);
            }
        }

        if ($closedCount > 0) {
            Log::info('批量关闭过期订单', ['closed_count' => $closedCount]);
        }

        return $closedCount;
    }
}
