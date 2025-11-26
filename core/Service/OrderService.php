<?php

declare(strict_types = 1);

namespace Core\Service;

use app\model\MerchantWalletRecord;
use app\model\Order;
use app\model\OrderBuyer;
use Carbon\Carbon;
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
     *
     * 当订单支付成功时调用此方法，更新订单状态及相关信息
     *
     * @param bool            $isAsync       是否为异步通知
     * @param string          $trade_no      系统交易号
     * @param string|int|null $api_trade_no  API交易号
     * @param string|int|null $bill_trade_no 账单交易号
     * @param string|int|null $mch_trade_no  商户交易号
     * @param string|int|null $payment_time  支付时间
     * @param array           $buyer         买家信息
     *
     * @return void
     * @throws Throwable
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

            // 验证订单状态转换是否有效
            if (!self::isValidStatusTransition($order['trade_state'], $newStatus)) {
                throw new Exception("交易状态不能从 $oldStatus 转换为 $newStatus");
            }

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

            // 只在有值时才更新外部交易号
            if ($api_trade_no !== null) $order->api_trade_no = $api_trade_no;
            if ($bill_trade_no !== null) $order->bill_trade_no = $bill_trade_no;
            if ($mch_trade_no !== null) $order->mch_trade_no = $mch_trade_no;

            $order->trade_state  = $newStatus;
            $order->payment_time = $payment_time === null ? time() : (is_numeric($payment_time) ? (int)$payment_time : $payment_time);

            // 根据结算周期处理订单结算逻辑
            if ($order->settle_cycle <= 0) {
                // 将订单结算状态标记为已结算
                $order->settle_state = Order::SETTLE_STATE_COMPLETED;
                if ($order->settle_cycle === 0) {
                    // 增加商户可用余额
                    MerchantWalletRecord::changeAvailable($order->merchant_id, $order->receipt_amount, '订单收益', true, $order->trade_no, '自动结算');
                }
            } else {
                // 将订单结算状态标记为结算中
                $order->settle_state = Order::SETTLE_STATE_PROCESSING;
                // 增加商户不可用余额
                MerchantWalletRecord::changeUnAvailable($order->merchant_id, $order->receipt_amount, '延迟结算', true, $order->trade_no);
                // 使用Redis队列等待订单结算
                $delay = $order->settle_cycle * 10;
                if (!SyncQueue::send('order-settle', $order->trade_no, $delay)) {
                    // 将订单结算状态标记为待结算
                    $order->settle_state = Order::SETTLE_STATE_FAILED;
                    Log::error("订单延迟结算队列投递失败：" . $order->trade_no);
                }
            }

            $order->save();

            Db::commit();

            // 如果是异步通知则同步通知下游
            if ($isAsync) {
                self::sendAsyncNotification($trade_no, $order);
            }
        } catch (Throwable $e) {
            Db::rollBack();
            Log::error("订单状态更新失败：" . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 发送异步通知
     *
     * 向指定队列发送订单状态变更的异步通知消息，用于通知下游系统订单状态的更新
     * 包括订单的基本信息、支付信息以及签名等数据
     *
     * @param string     $tradeNo   系统交易号，用于标识唯一订单
     * @param Order|null $order     订单对象，如果未提供将根据$tradeNo从数据库查询
     * @param string     $queueName 队列名称，默认为'order-notification'
     * @return string 签名字符串
     * @throws Exception 当签名生成过程出现错误时抛出异常
     */
    public static function sendAsyncNotification(string $tradeNo, ?Order $order = null, string $queueName = 'order-notification'): string
    {
        if ($order === null) {
            $order = Order::where('trade_no', $tradeNo)->first();
        }
        if (!$order) {
            throw new Exception("订单不存在：" . $tradeNo);
        }

        // 构建通知数据
        $queueData         = [
            'trade_no'         => $order->trade_no,
            'out_trade_no'     => $order->out_trade_no,
            'bill_trade_no'    => $order->bill_trade_no,
            'total_amount'     => $order->total_amount,
            'buyer_pay_amount' => $order->buyer_pay_amount,
            'receipt_amount'   => $order->receipt_amount,
            'attach'           => $order->attach,
            'trade_state'      => $order->trade_state,
            'create_time'      => $order->create_time_with_zone,
            'payment_time'     => $order->payment_time_with_zone,
            'timestamp'        => time(),
            'sign_type'        => 'rsa2',
        ];
        $buildSignature    = SignatureUtil::buildSignature($queueData, $queueData['sign_type'], sys_config('payment', 'system_rsa2_private_key', 'Rodots'));
        $queueData['sign'] = $buildSignature['sign'];

        // 使用Redis队列发送异步通知
        if (!SyncQueue::send($queueName, $queueData)) {
            Log::error("订单异步通知队列{$queueName}投递失败：" . $tradeNo);
        }

        return $buildSignature['sign_string'];
    }

    /**
     * 构建同步通知参数
     *
     * @param array $order 订单数据（包含['trade_no', 'out_trade_no', 'bill_trade_no', 'total_amount', 'attach', 'trade_state', 'return_url', 'create_time', 'payment_time']）
     * @return string
     * @throws Exception
     */
    public static function buildSyncNotificationParams(array $order): string
    {
        $return_url = $order['return_url'];
        unset($order['return_url']);

        // 格式化create_time与payment_time
        $order['create_time']  = Carbon::parse($order['create_time'])->timezone(config('app.default_timezone'))->format('Y-m-d\TH:i:sP');
        $order['payment_time'] = Carbon::parse($order['payment_time'])->timezone(config('app.default_timezone'))->format('Y-m-d\TH:i:sP');
        // 添加时间戳与签名
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
