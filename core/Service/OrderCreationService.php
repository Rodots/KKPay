<?php

declare(strict_types = 1);

namespace Core\Service;

use app\model\Order;
use app\model\Merchant;
use app\model\OrderBuyer;
use app\model\PaymentChannelAccount;
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
     * @throws PaymentException
     */
    public static function createOrder(array $bizContent, Merchant $merchant, string $clientIp): array
    {
        // 开启事务
        Db::beginTransaction();

        try {
            // 1. 风控检查
            if (RiskService::checkIpBlacklist($clientIp)) {
                throw new PaymentException('系统异常');
            }

            // 2. 业务验证
            // self::validateBusinessRules($merchant->id, $bizContent);

            // 3. 选择支付通道收款账户
            $paymentChannelAccount = self::selectPaymentChannel($bizContent);

            // 4. 创建订单记录
            $order = self::createOrderRecord($bizContent, $merchant->id, $paymentChannelAccount);

            // 5. 创建关联信息
            $orderBuyer = self::createOrderRelatedInfo($order->trade_no, $bizContent, $clientIp);

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();

            Log::error('订单创建失败', [
                'merchant_id'  => $merchant->id,
                'out_trade_no' => $bizContent['out_trade_no'] ?? '',
                'error'        => $e->getMessage(),
                'ip'           => $clientIp
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
     */
    private static function validateBusinessRules(int $merchantId, array $bizContent): void
    {
        // 验证订单是否已存在
        // if (Order::checkOutTradeNoExists($merchantId, $bizContent['out_trade_no'])) {
        //     throw new PaymentException('商户订单号已存在');
        // }

        // 其他业务验证可以在这里添加
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
     * 创建订单记录（包含完整支付信息）
     */
    private static function createOrderRecord(array $bizContent, int $merchantId, ?PaymentChannelAccount $paymentChannelAccount = null): Order
    {
        // 准备支付信息
        if ($paymentChannelAccount) {
            // 有支付通道时，使用通道信息
            $paymentType             = $paymentChannelAccount->paymentChannel->payment_type;
            $paymentChannelAccountId = $paymentChannelAccount->id;
        } elseif (!empty($bizContent['payment_type'])) {
            // 有支付类型但无通道时，使用传入的支付类型
            $paymentType             = $bizContent['payment_type'];
            $paymentChannelAccountId = 0;
        } else {
            // 无支付方式时，设置默认值
            $paymentType             = 'None';
            $paymentChannelAccountId = 0;
        }

        $fillData = [
            'out_trade_no'               => $bizContent['out_trade_no'],
            'merchant_id'                => $merchantId,
            'payment_type'               => $paymentType,
            'payment_channel_account_id' => $paymentChannelAccountId,
            'subject'                    => $bizContent['subject'],
            'total_amount'               => $bizContent['total_amount'],
            'buyer_pay_amount'           => $bizContent['total_amount'],
            'receipt_amount'             => $bizContent['total_amount'],
            'notify_url'                 => $bizContent['notify_url'],
            'return_url'                 => $bizContent['return_url'],
            'attach'                     => $bizContent['attach'] ?: null,
            'quit_url'                   => $bizContent['quit_url'] ?: '',
            'domain'                     => extract_domain($bizContent['return_url']) ?: extract_domain($bizContent['notify_url']),
            'close_time'                 => $bizContent['close_time'] ?: null,
        ];

        return Order::createOrderRecord($fillData);
    }

    /**
     * 创建订单关联买家信息
     */
    private static function createOrderRelatedInfo(string $trade_no, array $bizContent, string $clientIp): OrderBuyer
    {
        $data = [
            'trade_no'   => $trade_no,
            'ip'         => $clientIp,
            'user_agent' => $bizContent['user_agent'] ?? null,
        ];
        return OrderBuyer::create($data);
    }
}
