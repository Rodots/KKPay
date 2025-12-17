<?php

declare(strict_types = 1);

namespace Core\Service;

use app\model\Merchant;
use app\model\MerchantEncryption;
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
        Db::beginTransaction();
        try {
            // 获取并锁定订单，防止并发处理
            if (!$order = Order::where('trade_no', $trade_no)->lockForUpdate()->first()) {
                Db::rollBack();
                return;
            }

            // 幂等性检查：如果订单已经是成功状态，直接返回
            if ($order->trade_state === Order::TRADE_STATE_SUCCESS) {
                Db::rollBack();
                return;
            }

            $oldStatus = $order->trade_state;
            $newStatus = Order::TRADE_STATE_SUCCESS;
            // 验证订单状态转换是否有效
            if (!self::isValidStatusTransition($oldStatus, $newStatus, $isAdmin)) {
                throw new Exception("交易状态不能从 $oldStatus 转换为 $newStatus");
            }

            // 过滤并更新买家信息
            if (!empty($buyer)) {
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
                // 如果该订单的结算周期为实时结算则一同校验该商户是否拥有结算权限
                if ($order->settle_cycle === 0 && Merchant::where('id', $order->merchant_id)->whereJsonContains('competence', 'settle')->exists()) {
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
     * 构建基础通知数据（不包含时间戳和签名）
     * 这些数据在订单支付成功后就固定不变
     *
     * @param Order $order
     * @return array
     */
    public static function buildBaseNotificationData(Order $order): array
    {
        return [
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
            'sign_type' => $order->sign_type
        ];
    }

    /**
     * 获取签名密钥
     */
    public static function getSignKey(string $signType, int $merchantId): string
    {
        return $signType === MerchantEncryption::SIGN_TYPE_SHA256withRSA ? sys_config('payment', 'system_rsa2_private_key', '') : MerchantEncryption::where('merchant_id', $merchantId)->value('hash_key');
    }

    /**
     * 构建完整通知数据（包含实时时间戳和签名）
     * 用于手动请求或即时发送
     *
     * @param Order $order
     * @return array
     *
     * @throws Exception
     */
    public static function buildFullNotificationData(Order $order): array
    {
        $baseData              = self::buildBaseNotificationData($order);
        $baseData['timestamp'] = time();
        $buildSignature        = SignatureUtil::buildSignature($baseData, $order->sign_type, self::getSignKey($order->sign_type, $order->merchant_id));
        $baseData['sign']      = $buildSignature['sign'];
        return [
            'params'      => $baseData,
            'sign_string' => $buildSignature['sign_string']
        ];
    }

    /**
     * 发送订单状态变更的异步通知
     *
     * @param string     $tradeNo  系统内部交易号，唯一标识一笔订单
     * @param Order|null $order    可选的订单模型实例；若未提供，则根据 $tradeNo 查询数据库
     * @param bool       $isManual 是否为手动触发
     * @return void
     *
     * @throws Exception 当订单不存在或签名生成失败时抛出异常
     */
    public static function sendAsyncNotification(string $tradeNo, ?Order $order = null, bool $isManual = false): void
    {
        if ($order === null) {
            $order = Order::where('trade_no', $tradeNo)->first();
        }
        if (!$order) {
            throw new Exception("订单不存在：" . $tradeNo);
        }

        if ($isManual) {
            $queueName = 'order-notification-manual';
            $queueData = self::buildFullNotificationData($order);
        } else {
            $queueName = 'order-notification';
            $queueData = self::buildBaseNotificationData($order);
        }

        // 使用Redis队列发送异步通知
        if (!SyncQueue::send($queueName, $queueData)) {
            Log::error("订单异步通知队列{$queueName}投递失败：" . $tradeNo);
        }
    }

    /**
     * 构建同步通知参数
     *
     * @param array $order 订单数据（包含['trade_no', 'out_trade_no', 'bill_trade_no', 'merchant_id', 'total_amount', 'attach', 'trade_state', 'return_url', 'create_time', 'payment_time', 'sign_type']）
     * @return string
     * @throws Exception
     */
    public static function buildSyncNotificationParams(array $order): string
    {
        // 过滤$order数组，只保留必要参数
        $params = array_intersect_key($order, ['trade_no' => 0, 'out_trade_no' => 0, 'bill_trade_no' => 0, 'total_amount' => 0, 'attach' => 0, 'trade_state' => 0, 'create_time' => 0, 'payment_time' => 0, 'sign_type' => 0]);

        // 添加当前请求时间戳
        $params['timestamp'] = time();
        // 统一时区转换逻辑
        $timezone               = config('app.default_timezone');
        $params['create_time']  = Carbon::parse($params['create_time'])->timezone($timezone)->format('Y-m-d\TH:i:sP');
        $params['payment_time'] = Carbon::parse($params['payment_time'])->timezone($timezone)->format('Y-m-d\TH:i:sP');

        // 签名密钥获取
        $signKey = self::getSignKey($order['sign_type'], $order['merchant_id']);
        // 生成签名
        $params['sign'] = SignatureUtil::buildSignature($params, $params['sign_type'], $signKey)['sign'];

        // 构建返回URL
        $returnUrl = $order['return_url'];
        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return $returnUrl . $separator . http_build_query($params);
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
                    // 验证当前订单的结算状态是否为失败（可能是因为冻结或无结算权限而导致应结算时未结算），如果是则立即尝试执行结算
                    if (Merchant::where('id', $order->merchant_id)->whereJsonContains('competence', 'settle')->exists()) {
                        // 执行商户钱包金额变更操作
                        MerchantWalletRecord::changeAvailable($order->merchant_id, $order->receipt_amount, '订单收益', true, $order->trade_no, '补偿结算(订单原为冻结状态，解冻后恢复结算)', true);
                        $order->settle_state = Order::SETTLE_STATE_COMPLETED;
                    }
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
