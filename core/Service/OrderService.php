<?php

declare(strict_types = 1);

namespace Core\Service;

use app\model\Order;
use app\model\OrderBuyer;
use Core\Utils\SignatureUtil;
use Exception;
use support\Log;
use support\Db;
use Throwable;
use Webman\RedisQueue\Redis as SyncQueue;

/**
 * 订单服务类
 * 负责订单状态管理和业务逻辑
 */
class OrderService
{
    /**
     * 验证订单状态转换是否合法
     *
     * 此方法定义了订单状态的合法转换规则，只有符合规则的转换才会被允许。
     * 对于管理员操作，不限制状态转换；对于普通操作，需要遵循预定义的状态转换图。
     *
     * @param string $fromStatus 订单当前状态
     * @param string $toStatus   订单目标状态
     * @param bool   $admin      是否为管理员操作，默认为false
     * @return bool              状态转换是否合法，合法返回true，否则返回false
     */
    private static function isValidStatusTransition(string $fromStatus, string $toStatus, bool $admin = false): bool
    {
        // 如果为管理员操作则不限制
        if ($admin) {
            return true;
        }

        // 定义合法的状态转换映射表
        $validTransitions = [
            Order::TRADE_STATE_WAIT_PAY => [
                Order::TRADE_STATE_SUCCESS,
                Order::TRADE_STATE_FINISHED,
                Order::TRADE_STATE_CLOSED
            ],
            Order::TRADE_STATE_SUCCESS  => [
                Order::TRADE_STATE_FINISHED,
                Order::TRADE_STATE_FROZEN
            ],
            Order::TRADE_STATE_FROZEN   => [
                Order::TRADE_STATE_SUCCESS,
                Order::TRADE_STATE_FINISHED
            ],
            Order::TRADE_STATE_CLOSED   => [], // 交易关闭的订单不能再转换
            Order::TRADE_STATE_FINISHED => [] // 交易结束的订单不能再转换
        ];

        // 检查目标状态是否在当前状态允许转换的列表中
        return in_array($toStatus, $validTransitions[$fromStatus] ?? []);
    }

    /**
     * 处理支付成功
     */
    public static function handlePaymentSuccess(bool $isAsync, string $trade_no, string|int|null $api_trade_no = null, string|int|null $bill_trade_no = null, string|int|null $mch_trade_no = null, string|int|null $payment_time = null, array $buyer = []): void
    {
        $order = Order::where('trade_no', $trade_no)->first();

        if (!$order) {
            return;
        }

        Db::beginTransaction();

        try {
            $oldStatus = $order->trade_state;
            $newStatus = Order::TRADE_STATE_SUCCESS;

            if (!self::isValidStatusTransition($order['trade_state'], $newStatus)) {
                throw new Exception("交易状态不能从 $oldStatus 转换为 $newStatus");
            }

            // 只在有值时才更新
            if ($api_trade_no !== null) $order->api_trade_no = $api_trade_no;
            if ($bill_trade_no !== null) $order->bill_trade_no = $bill_trade_no;
            if ($mch_trade_no !== null) $order->mch_trade_no = $mch_trade_no;

            $order->trade_state  = $newStatus;
            $order->settle_state = Order::SETTLE_STATE_PROCESSING;
            $order->payment_time = $payment_time === null ? time() : (is_numeric($payment_time) ? (int)$payment_time : $payment_time);
            $order->save();

            // 过滤并更新买家信息
            $filteredBuyer = array_intersect_key($buyer, [
                'ip'            => 0,
                'user_agent'    => 0,
                'user_id'       => 0,
                'buyer_open_id' => 0,
                'phone'         => 0
            ]);

            if (!empty($filteredBuyer)) {
                OrderBuyer::where('trade_no', $order->trade_no)->update($filteredBuyer);
            }

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            Log::error("订单状态更新失败：" . $e->getMessage());
            return;
        }

        // 异步通知
        if ($isAsync) {
            self::sendAsyncNotification($trade_no, $order);
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
    }

    /**
     * 发送异步通知
     */
    public static function sendAsyncNotification(string $tradeNo, ?Order $order = null, string $queueName = 'order-notification'): void
    {
        if ($order === null) {
            $order = Order::where('trade_no', $tradeNo)->first();
        }
        if (!$order) {
            return;
        }

        // 构建通知数据
        $notifyData         = [
            'trade_no'         => $order->trade_no,
            'out_trade_no'     => $order->out_trade_no,
            'bill_trade_no'    => $order->bill_trade_no,
            'total_amount'     => $order->total_amount,
            'buyer_pay_amount' => $order->buyer_pay_amount,
            'receipt_amount'   => $order->receipt_amount,
            'attach'           => $order->attach,
            'trade_state'      => $order->trade_state,
            'create_time'      => $order->create_time,
            'payment_time'     => $order->payment_time,
            'timestamp'        => time(),
            'sign_type'        => 'rsa2',
        ];
        $notifyData['sign'] = SignatureUtil::buildSignature($notifyData, $notifyData['sign_type'], sys_config('payment', 'system_rsa2_private_key', 'Rodots'));

        // 使用Redis队列发送异步通知
        if (!SyncQueue::send($queueName, $notifyData)) {
            Log::error("订单异步通知队列{$queueName}投递失败：" . $tradeNo);
        }
    }

    /**
     * 构建同步通知参数
     *
     * @param array $order 订单数据（包含['trade_no', 'out_trade_no', 'bill_trade_no', 'total_amount', 'attach', 'trade_state', 'return_url', 'create_time', 'payment_time']）
     */
    public static function buildSyncNotificationParams(array $order): string
    {
        $return_url = $order['return_url'];
        unset($order['return_url']);

        $order['timestamp'] = time();
        $order['sign_type'] = 'rsa2';
        $order['sign']      = SignatureUtil::buildSignature($order, $order['sign_type'], sys_config('payment', 'system_rsa2_private_key', 'Rodots'));

        $separator   = str_contains($return_url, '?') ? '&' : '?';
        $queryString = http_build_query($order);

        return $return_url . $separator . $queryString;
    }

    /**
     * 检查订单是否可以支付
     */
    public static function canPay(Order $order): bool
    {
        // 检查订单状态
        if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY || $order->trade_state === Order::TRADE_STATE_CLOSED) {
            return false;
        }

        return true;
    }
}
