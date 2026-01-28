<?php

declare(strict_types=1);

namespace Core\Service;

use app\model\MerchantWalletRecord;
use app\model\Order;
use app\model\OrderRefund;
use Core\Utils\PaymentGatewayUtil;
use Exception;
use support\Db;
use Throwable;

class RefundService
{
    /**
     * 处理退款
     *
     * @param string      $trade_no      系统交易号
     * @param string      $amount        退款金额
     * @param string      $initiate_type 退款发起方式
     * @param bool        $refund_type   退款类型 (false: 手动, true: 自动)
     * @param bool        $fee_bearer    退款服务费承担方 (false: 商户承担, true: 平台承担)
     * @param string|null $out_biz_no    商家业务号
     * @param string|null $reason        退款原因
     * @return array
     */
    public static function handle(string $trade_no, string $amount, string $initiate_type, bool $refund_type = false, bool $fee_bearer = false, ?string $out_biz_no = null, ?string $reason = null): array
    {
        Db::beginTransaction();
        try {
            if (bccomp($amount, '0', 2) <= 0) {
                throw new Exception('退款金额必须大于0');
            }

            $order = Order::withSum('refunds', 'amount')->where('trade_no', $trade_no)->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('订单不存在');
            }

            // 检查订单状态（支持交易成功和部分退款状态的订单进行退款）
            if (!in_array($order->trade_state, [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_REFUND], true)) {
                throw new Exception("订单当前状态为[$order->trade_state_text]，无法进行退款");
            }

            if ($order->settle_state === Order::SETTLE_STATE_PROCESSING) {
                throw new Exception('订单未完成结算，无法进行退款');
            }

            $total_amount     = (string)$order->getOriginal('total_amount');
            $buyer_pay_amount = (string)$order->getOriginal('buyer_pay_amount');
            $fee_amount       = (string)$order->getOriginal('fee_amount');
            $refunded_amount  = $order->refunds_sum_amount ?? '0';
            $remaining_amount = bcsub($buyer_pay_amount, $refunded_amount, 2);

            if (bccomp($amount, $remaining_amount, 2) > 0) {
                throw new Exception("本次退款金额不能大于剩余可退款金额{$remaining_amount}元");
            }

            // 扣除商户实收金额
            MerchantWalletRecord::changeAvailable($order->merchant_id, bcmul($amount, '-1', 2), '订单退款', $order->trade_no, '退款扣除收益');

            // 计算应退还的平台服务费
            $refund_fee_amount = '0.00';
            if ($fee_bearer && bccomp($fee_amount, '0', 2) > 0) {
                $refund_fee_amount = self::calculateRefundFee($total_amount, $fee_amount, $amount);
                MerchantWalletRecord::changeAvailable($order->merchant_id, $refund_fee_amount, '订单服务费退款', $order->trade_no, '退款退回平台扣除的订单服务费');
            }

            // 新增退款记录
            $orderRefund                    = new OrderRefund();
            $orderRefund->merchant_id       = $order->merchant_id;
            $orderRefund->initiate_type     = $initiate_type;
            $orderRefund->refund_type       = $refund_type;
            $orderRefund->trade_no          = $trade_no;
            $orderRefund->out_biz_no        = $out_biz_no ?: null;
            $orderRefund->amount            = $amount;
            $orderRefund->refund_fee_amount = $refund_fee_amount;
            $orderRefund->fee_bearer        = $fee_bearer;
            $orderRefund->reason            = $reason;
            $orderRefund->save();

            // 更新订单状态
            $new_refunded_amount = bcadd($refunded_amount, $amount, 2);
            $order->trade_state  = bccomp($new_refunded_amount, $buyer_pay_amount, 2) >= 0 ? Order::TRADE_STATE_FINISHED : Order::TRADE_STATE_REFUND;
            $order->save();

            // API自动退款：调用支付网关退款接口
            $gatewayReturn = [];
            if ($refund_type) {
                if (empty($order->api_trade_no)) {
                    throw new Exception('订单未获取到对应接口订单号，无法进行API自动退款');
                }

                $paymentChannelAccount = $order->paymentChannelAccount;
                $gateway               = $paymentChannelAccount?->paymentChannel?->gateway;
                if (empty($gateway)) {
                    throw new Exception('支付网关获取异常，无法完成API自动退款');
                }

                $gatewayReturn = PaymentGatewayUtil::loadGatewayWithSpread($gateway, 'refund', [
                    'order'         => $order->toArray(),
                    'channel'       => $paymentChannelAccount->config,
                    'refund_record' => $orderRefund->toArray(),
                ]);

                if (!$gatewayReturn['state'] || empty($gatewayReturn['api_refund_no'])) {
                    throw new Exception($gatewayReturn['message'] ?? '网关退款失败');
                }
                OrderRefund::where('id', $orderRefund->id)->update(['api_refund_no' => $gatewayReturn['api_refund_no']]);
            }

            Db::commit();
            return ['state' => true, 'refund_record' => $orderRefund->toArray(), 'gateway_return' => $gatewayReturn];
        } catch (Throwable $e) {
            Db::rollBack();
            return ['state' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 商户API发起退款（支持幂等）
     *
     * @param string      $tradeNo      平台订单号
     * @param string      $refundAmount 退款金额
     * @param string      $reason       退款原因
     * @param string|null $outBizNo     商户退款业务号（用于幂等）
     * @param int         $merchantId   商户ID
     * @return array
     */
    public static function apiRefund(string $tradeNo, string $refundAmount, string $reason = '商户发起退款', ?string $outBizNo = null, int $merchantId = 0): array
    {
        $refundAmount = bcadd($refundAmount, '0', 2);

        // 幂等处理
        if ($outBizNo !== null && $outBizNo !== '') {
            $existingRefund = OrderRefund::where('merchant_id', $merchantId)->where('out_biz_no', $outBizNo)->first(['id', 'trade_no', 'amount']);
            if ($existingRefund !== null) {
                if ($existingRefund->trade_no !== $tradeNo || bccomp((string)$existingRefund->amount, $refundAmount, 2) !== 0) {
                    return ['success' => false, 'message' => '商户退款业务号已存在，但订单号或金额不一致', 'refund_id' => null];
                }
                return ['success' => true, 'message' => '退款成功', 'refund_id' => $existingRefund->id];
            }
        }

        // 验证订单归属
        $order = Order::where('trade_no', $tradeNo)->first(['merchant_id']);
        if ($order === null || $order->merchant_id !== $merchantId) {
            return ['success' => false, 'message' => '订单不存在或不属于当前商户', 'refund_id' => null];
        }

        $fee_bearer = sys_config('payment', 'api_refund_fee_bearer', 'merchant') === 'platform';
        $result     = self::handle($tradeNo, $refundAmount, 'api', true, $fee_bearer, $outBizNo, $reason);

        return $result['state']
            ? ['success' => true, 'message' => '退款成功', 'refund_id' => $result['refund_record']['id']]
            : ['success' => false, 'message' => $result['msg'], 'refund_id' => null];
    }

    /**
     * 计算应退还的平台服务费
     *
     * @param string $total_amount  订单金额
     * @param string $fee_amount    平台服务费
     * @param string $refund_amount 退款金额
     * @return string 应退还的平台服务费
     */
    private static function calculateRefundFee(string $total_amount, string $fee_amount, string $refund_amount): string
    {
        if (bccomp($total_amount, '0', 2) <= 0 || bccomp($fee_amount, '0', 2) <= 0) {
            return '0.00';
        }

        // 服务费 × (退款金额 / 订单金额)，使用8位精度计算，结果保留2位
        $refund_fee = bcmul($fee_amount, bcdiv($refund_amount, $total_amount, 8), 2);

        return bccomp($refund_fee, $fee_amount, 2) > 0 ? bcadd($fee_amount, '0', 2) : $refund_fee;
    }
}
