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
     * @param bool   $isAdmin    是否为管理员操作，默认为false
     * @return bool              状态转换是否合法，合法返回true，否则返回false
     */
    private static function isValidStatusTransition(string $fromStatus, string $toStatus, bool $isAdmin = false): bool
    {
        // 如果为管理员操作则不限制
        if ($isAdmin) {
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
                Order::TRADE_STATE_SUCCESS
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
     * @param bool            $isAdmin       是否为管理员操作，默认为false
     *
     * @return void
     * @throws Throwable
     */
    public static function handlePaymentSuccess(bool $isAsync, string $trade_no, string|int|null $api_trade_no = null, string|int|null $bill_trade_no = null, string|int|null $mch_trade_no = null, string|int|null $payment_time = null, array $buyer = [], bool $isAdmin = false): void
    {
        $order = Order::where('trade_no', $trade_no)->first();

        if (!$order) {
            return;
        }

        $oldStatus = $order->trade_state;
        $newStatus = Order::TRADE_STATE_SUCCESS;
        // 验证订单状态转换是否有效
        if (!self::isValidStatusTransition($order['trade_state'], $newStatus, $isAdmin)) {
            throw new Exception("交易状态不能从 $oldStatus 转换为 $newStatus");
        }

        Db::beginTransaction();
        try {
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

            // 如果是异步通知则同步通知下游
            if ($isAsync) {
                self::sendAsyncNotification($trade_no, $order);
            }

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            Log::error("订单处理交易成功时出现异常：" . $e->getMessage(), ['trade_no' => $trade_no]);
            throw $e;
        }
    }

    /**
     * 发送订单状态变更的异步通知
     *
     * 本方法用于将订单状态更新信息以异步消息的形式发送至指定的消息队列（默认为 'order-notification'），
     * 供下游系统消费处理。通知数据包含订单核心字段、时间信息、金额信息及基于 RSA2 算法生成的签名。
     * 根据 $isServer 参数决定行为模式。
     *
     * 若传入的 $order 为 null，将根据 $tradeNo 自动从数据库加载订单。
     * 若未找到对应订单，将抛出异常。
     *
     * @param string     $tradeNo   系统内部交易号，唯一标识一笔订单
     * @param Order|null $order     可选的订单模型实例；若未提供，则根据 $tradeNo 查询数据库
     * @param string     $queueName 目标队列名称，默认为 'order-notification'
     * @param bool       $isServer  是否以服务端模式运行：
     *                              - true：发送消息到队列，并返回签名原始字符串；
     *                              - false：不发送队列，返回完整 JSON 数据（含签名），用于模拟请求体
     *
     * @return string
     *
     * @throws Exception 当订单不存在或签名生成失败时抛出异常
     */
    public static function sendAsyncNotification(string $tradeNo, ?Order $order = null, string $queueName = 'order-notification', bool $isServer = true): string
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
        $buildSignature    = SignatureUtil::buildSignature($queueData, $queueData['sign_type'], sys_config('payment', 'system_rsa2_private_key', ''));
        $queueData['sign'] = $buildSignature['sign'];

        if ($isServer) {
            // 使用Redis队列发送异步通知
            if (!SyncQueue::send($queueName, $queueData)) {
                Log::error("订单异步通知队列{$queueName}投递失败：" . $tradeNo);
            }
            return $buildSignature['sign_string'];
        }

        return json_encode($queueData);
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
        // 过滤$order数组，只保留必要参数
        $order      = array_intersect_key($order, ['trade_no' => 0, 'out_trade_no' => 0, 'bill_trade_no' => 0, 'total_amount' => 0, 'attach' => 0, 'trade_state' => 0, 'return_url' => 0, 'create_time' => 0, 'payment_time' => 0]);
        $return_url = $order['return_url'];
        unset($order['return_url']);

        // 格式化create_time与payment_time
        $order['create_time']  = Carbon::parse($order['create_time'])->timezone(config('app.default_timezone'))->format('Y-m-d\TH:i:sP');
        $order['payment_time'] = Carbon::parse($order['payment_time'])->timezone(config('app.default_timezone'))->format('Y-m-d\TH:i:sP');
        // 添加时间戳与签名
        $order['timestamp'] = time();
        $order['sign_type'] = 'rsa2';
        $order['sign']      = SignatureUtil::buildSignature($order, $order['sign_type'], sys_config('payment', 'system_rsa2_private_key', ''));

        $separator   = str_contains($return_url, '?') ? '&' : '?';
        $queryString = http_build_query($order);

        return $return_url . $separator . $queryString;
    }

    /**
     * 根据指定目标状态对订单执行冻结或解冻操作，并同步更新商户钱包余额。
     *
     * - 若目标状态为冻结（TRADE_STATE_FROZEN）：
     *   - 仅当订单已结算（SETTLE_STATE_COMPLETED）时，将订单金额从商户可用余额转移至不可用余额。
     *
     * - 若目标状态为解冻（非冻结状态）：
     *   - 若订单已结算，则将冻结金额释放回商户可用余额；
     *   - 若订单结算状态为失败（SETTLE_STATE_FAILED），则视为因冻结导致未结算，
     *     此时执行补偿性结算：将订单金额计入商户可用余额，并更新结算状态为已完成。
     *
     * 本操作在数据库事务中执行，确保状态变更与钱包记录的一致性。
     *
     * @param string $tradeNo     订单号，用于定位唯一订单
     * @param string $targetState 目标交易状态，应为 Order::TRADE_STATE_FROZEN 或其他有效解冻状态
     *
     * @return void
     * @throws Throwable 当数据库操作或钱包变更过程中发生异常时抛出
     */
    public static function handleFreezeOrThaw(string $tradeNo, string $targetState): void
    {
        $order = Order::where('trade_no', $tradeNo)->first();

        if (!$order) {
            throw new Exception('订单不存在');
        }

        // 验证订单状态转换是否有效
        if (!self::isValidStatusTransition($order->trade_state, $targetState)) {
            throw new Exception("交易状态不能从 $order->trade_state 转换为 $targetState");
        }

        // 验证订单金额
        if ($order->receipt_amount <= 0) {
            throw new Exception("订单金额无效: $order->receipt_amount");
        }

        Db::beginTransaction();
        try {
            $order->trade_state = $targetState;

            // 根据目标状态处理对应的操作
            if ($targetState === Order::TRADE_STATE_FROZEN) {
                // 冻结操作，判断该订单已经结算了才冻结可用余额
                if ($order->settle_state === Order::SETTLE_STATE_COMPLETED) {
                    MerchantWalletRecord::changeUnAvailable($order->merchant_id, $order->receipt_amount, '订单冻结', true, $order->trade_no, '订单已结算，需冻结可用余额', true);
                }
            } else {
                // 解冻操作
                if ($order->settle_state === Order::SETTLE_STATE_COMPLETED) {
                    MerchantWalletRecord::changeAvailable($order->merchant_id, $order->receipt_amount, '订单解冻', true, $order->trade_no, '将原冻结的可用余额释放', true);
                } elseif ($order->settle_state === Order::SETTLE_STATE_FAILED) {
                    // 验证当前订单的结算状态是否为失败（可能是因为冻结而导致应结算时未结算），如果是则立即执行结算
                    // 执行商户钱包金额变更操作
                    MerchantWalletRecord::changeAvailable($order->merchant_id, $order->receipt_amount, '订单收益', true, $order->trade_no, '补偿结算(订单原为冻结状态，解冻后恢复结算)', true);
                    $order->settle_state = Order::SETTLE_STATE_COMPLETED;
                }
            }
            $order->save();

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            Log::error("订单冻结/解冻失败：" . $e->getMessage(), ['trade_no' => $tradeNo, 'target_state' => $targetState]);
            throw $e;
        }
    }
}
