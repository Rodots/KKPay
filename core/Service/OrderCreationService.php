<?php

declare(strict_types=1);

namespace Core\Service;

use app\model\Order;
use app\model\Merchant;
use app\model\OrderBuyer;
use app\model\PaymentChannelAccount;
use Carbon\Carbon;
use Core\Exception\PaymentException;
use support\Log;
use support\Db;
use Throwable;

/**
 * 订单创建服务类
 * 专门负责订单创建的业务逻辑协调
 */
class OrderCreationService
{
    /**
     * 创建订单的完整流程
     *
     * @param array    $bizContent 业务参数
     * @param Merchant $merchant   商户对象
     * @return array [Order, ?PaymentChannelAccount, ?OrderBuyer]
     * @throws PaymentException 创建失败时抛出异常
     */
    public static function createOrder(array $bizContent, Merchant $merchant): array
    {
        // 开启事务
        Db::beginTransaction();

        try {
            // 业务验证 - 返回已存在的订单（如有）
            $existingOrder = self::validateBusinessRules($merchant->id, $bizContent);

            // 如果订单已存在且参数一致，直接返回（避免重复创建）
            if ($existingOrder !== null) {
                Db::commit();
                $paymentChannelAccount = $existingOrder->payment_channel_account_id > 0 ? PaymentChannelAccount::find($existingOrder->payment_channel_account_id) : null;
                // 重新加载 buyer 关联
                $existingOrder->load('buyer');
                return [$existingOrder, $paymentChannelAccount, $existingOrder->buyer];
            }

            // 选择支付通道收款账户
            $paymentChannelAccount = self::selectPaymentChannel($bizContent);

            // 创建订单记录
            $order = self::createOrderRecord($bizContent, $merchant->id, $paymentChannelAccount);

            // 创建订单关联信息
            $orderBuyer = OrderBuyer::create([
                'trade_no'   => $order->trade_no,
                'ip'         => $bizContent['buyer']['ip'],
                'user_agent' => $bizContent['buyer']['user_agent'],
                'real_name'  => $bizContent['buyer']['real_name'],
                'cert_no'    => $bizContent['buyer']['cert_no'],
                'cert_type'  => $bizContent['buyer']['cert_type'],
                'min_age'    => $bizContent['buyer']['min_age'],
                'mobile'     => $bizContent['buyer']['mobile'],
            ]);

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();

            Log::error('订单创建失败', [
                'merchant_id'  => $merchant->id,
                'out_trade_no' => $bizContent['out_trade_no'] ?? '',
                'error'        => $e->getMessage()
            ]);

            if ($e instanceof PaymentException) {
                throw $e;
            }

            throw new PaymentException('订单创建失败');
        }

        return [$order, $paymentChannelAccount, $orderBuyer];
    }

    /**
     * 验证业务规则
     *
     * 检查订单是否已存在、是否已支付、参数是否一致
     *
     * @param int   $merchantId 商户ID
     * @param array $bizContent 业务参数
     * @return Order|null 已存在的订单（参数一致时返回），或null表示需要创建新订单
     * @throws PaymentException 验证失败时抛出异常
     */
    private static function validateBusinessRules(int $merchantId, array $bizContent): ?Order
    {
        // 1. 查询现有订单（同一商户7天内的订单号）
        $oldOrder = Order::where('out_trade_no', $bizContent['out_trade_no'])->where([['merchant_id', '=', $merchantId], ['create_time', '>', Carbon::now()->subDays(7)]])->first();

        if ($oldOrder === null) {
            return null; // 无重复订单，需要创建新订单
        }

        // 2. 检查订单是否已支付或已完结
        if (in_array($oldOrder->trade_state, [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_FINISHED, Order::TRADE_STATE_FROZEN])) {
            throw new PaymentException('该订单号已被使用且已完成支付');
        }

        // 3. 检查订单是否已关闭
        if ($oldOrder->trade_state === Order::TRADE_STATE_CLOSED) {
            throw new PaymentException('该订单已关闭，请使用新的订单号');
        }

        // 4. 对于未支付订单，检查关键支付参数是否一致
        $paramChecks = [
            'subject'      => '订单标题',
            'total_amount' => '订单金额',
            'notify_url'   => '异步通知地址',
            'return_url'   => '同步通知地址',
            'attach'       => '附加参数'
        ];
        foreach ($paramChecks as $param => $label) {
            $oldValue = $oldOrder->$param;
            $newValue = $bizContent[$param] ?? null;
            // 金额使用 bccomp 比较，避免浮点数精度问题
            if ($param === 'total_amount') {
                if (bccomp($oldValue, $newValue, 2) !== 0) {
                    throw new PaymentException("订单已存在但$label($param)不一致，请使用新的订单号");
                }
            } elseif ($oldValue !== $newValue) {
                throw new PaymentException("订单已存在但$label($param)不一致，请使用新的订单号");
            }
        }

        return $oldOrder;
    }

    /**
     * 选择支付通道
     *
     * @throws PaymentException
     */
    private static function selectPaymentChannel(array $bizContent): ?PaymentChannelAccount
    {
        if (!empty($bizContent['payment_channel_code'])) {
            return PaymentChannelSelectionService::selectByCode($bizContent['payment_channel_code'], $bizContent['payment_type'], $bizContent['total_amount']);
        } elseif (!empty($bizContent['payment_type'])) {
            return PaymentChannelSelectionService::selectByType($bizContent['payment_type'], $bizContent['total_amount']);
        }

        return null;
    }

    /**
     * 创建订单记录（初始化）
     */
    private static function createOrderRecord(array $bizContent, int $merchantId, ?PaymentChannelAccount $paymentChannelAccount = null): Order
    {
        $receiptAmount = 0;
        $feeAmount     = 0;
        $profitAmount  = 0;
        $settleSycle   = 0;
        if ($paymentChannelAccount) {
            // 有支付通道时，使用通道信息
            $paymentType             = $paymentChannelAccount->paymentChannel->payment_type;
            $settleSycle             = $paymentChannelAccount->paymentChannel->settle_cycle;
            $paymentChannelAccountId = $paymentChannelAccount->id;
            [$receiptAmount, $feeAmount, $profitAmount] = self::calculateOrderFees($bizContent['total_amount'], $paymentChannelAccount);
        } elseif (!empty($bizContent['payment_type'])) {
            // 有支付类型但无通道时，使用传入的支付类型
            $paymentType             = $bizContent['payment_type'];
            $paymentChannelAccountId = 0;
        } else {
            // 无支付方式时，设置默认值
            $paymentType             = 'None';
            $paymentChannelAccountId = 0;
        }
        // TODO 根据商户的设定，判断是否由买家支付服务费，以计算买家实付金额

        $fillData = [
            'out_trade_no'               => $bizContent['out_trade_no'],
            'merchant_id'                => $merchantId,
            'payment_type'               => $paymentType,
            'payment_channel_account_id' => $paymentChannelAccountId,
            'subject'                    => $bizContent['subject'],
            'total_amount'               => $bizContent['total_amount'],
            'buyer_pay_amount'           => $bizContent['total_amount'],
            'receipt_amount'             => $receiptAmount,
            'fee_amount'                 => $feeAmount,
            'profit_amount'              => $profitAmount,
            'notify_url'                 => $bizContent['notify_url'],
            'return_url'                 => $bizContent['return_url'],
            'attach'                     => $bizContent['attach'] ?: null,
            'settle_cycle'               => $settleSycle,
            'sign_type'                  => $bizContent['sign_type'],
            'close_time'                 => $bizContent['close_time'] ?: null,
        ];

        return Order::createOrderRecord($fillData);
    }

    /**
     * 计算订单服务费、商户实收金额和利润
     *
     * @param string                $total_amount          订单金额
     * @param PaymentChannelAccount $paymentChannelAccount 支付渠道账户配置
     * @return array [商户实收金额, 服务费金额, 利润金额]
     */
    private static function calculateOrderFees(string $total_amount, PaymentChannelAccount $paymentChannelAccount): array
    {
        // 1. 格式化金额（确保两位小数）
        $total_amount = number_format((float)$total_amount, 2, '.', '');

        $paymentChannel = $paymentChannelAccount->paymentChannel;

        // 2. 获取服务费率
        $rate = $paymentChannelAccount->inherit_config ? (string)$paymentChannel->rate : (string)$paymentChannelAccount->rate;

        // 3. 计算基础服务费 (金额 * 费率 + 固定费用)
        $baseFee   = bcmul($total_amount, $rate, 4);
        $feeAmount = bcadd($baseFee, (string)$paymentChannel->fixed_fee, 4);

        // 4. 应用服务费上下限限制（服务费不能超过订单金额）
        $minFee    = (string)$paymentChannel->min_fee;
        $feeAmount = bccomp($feeAmount, $minFee, 4) < 0 ? $minFee : $feeAmount;

        // 如果存在最大服务费限制，则应用限制
        if (!empty($paymentChannel->max_fee)) {
            $maxFee    = (string)$paymentChannel->max_fee;
            $feeAmount = bccomp($feeAmount, $maxFee, 4) > 0 ? $maxFee : $feeAmount;
        }

        // 确保服务费不超过订单金额
        $feeAmount = bccomp($feeAmount, $total_amount, 4) > 0 ? $total_amount : $feeAmount;

        // 5. 计算成本
        $costAmount = bcadd(bcmul($total_amount, (string)$paymentChannel->cost, 4), (string)$paymentChannel->fixed_cost, 4);

        // 6. 计算商户实收金额（不能为负数）
        $receiptAmount = bcsub($total_amount, $feeAmount, 4);
        $receiptAmount = bccomp($receiptAmount, '0', 4) < 0 ? 0 : $receiptAmount;

        // 7. 计算利润（可为负数）
        $profitAmount = bcsub($feeAmount, $costAmount, 4);

        return [$receiptAmount, $feeAmount, $profitAmount];
    }
}
