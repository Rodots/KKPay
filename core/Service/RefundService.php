<?php

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
            // 参数校验
            if (bccomp($amount, '0', 2) <= 0) {
                throw new Exception('退款金额必须大于0');
            }

            // 查询订单并关联订单退款表
            $order = Order::withSum('refunds', 'amount')->where('trade_no', $trade_no)->lockForUpdate()->first();

            if (!$order) {
                throw new Exception('订单不存在');
            }

            // 检查订单状态
            if ($order->trade_state !== Order::TRADE_STATE_SUCCESS) {
                throw new Exception("订单当前状态为[$order->trade_state_text]，无法进行退款");
            }

            // 检查结算状态
            if ($order->settle_state === Order::SETTLE_STATE_PROCESSING) {
                throw new Exception('订单未完成结算，无法进行退款');
            }

            // 订单金额
            $total_amount = $order->getOriginal('total_amount');
            // 用户在交易中支付的金额（实付金额）
            $buyer_pay_amount = $order->getOriginal('buyer_pay_amount');
            // 平台服务费金额
            $fee_amount = $order->getOriginal('fee_amount');

            // 该订单已退款金额
            $refunded_amount = $order->refunds_sum_amount ?? '0';
            // 剩余可退款金额 = 实付金额 - 已退款金额
            $remaining_amount = bcsub($buyer_pay_amount, $refunded_amount, 2);
            // 检查退款金额是否超过剩余可退款金额
            if (bccomp($amount, $remaining_amount, 2) > 0) {
                throw new Exception("本次退款金额不能大于剩余可退款金额{$remaining_amount}元");
            }

            // 执行商户钱包金额变更操作（扣除商户实收金额，传入负数金额表示扣款）
            MerchantWalletRecord::changeAvailable($order->merchant_id, bcsub('0.00', $amount, 2), '订单退款', $order->trade_no, '退款扣除收益');

            // 计算应退还的平台服务费（如果平台承担服务费）
            $refund_fee_amount = '0';
            if ($fee_bearer && bccomp($fee_amount, '0', 2) > 0) {
                $refund_fee_amount = self::calculateRefundFee($total_amount, $fee_amount, $amount);
                // 加款传入正数金额
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

            // 判断订单是否全额退款完成（依据实付金额平账）
            $new_refunded_amount = bcadd($refunded_amount, $amount, 2);
            if (bccomp($new_refunded_amount, $buyer_pay_amount, 2) >= 0) {
                // 更新订单状态为交易结束
                $order->trade_state = Order::TRADE_STATE_FINISHED;
                $order->save();
            }

            // 如果退款类型为API自动退款，则尝试调用支付网关所对应的退款接口
            if ($refund_type) {
                if (empty($order->api_trade_no)) {
                    throw new Exception('订单未获取到对应接口订单号，无法进行API自动退款');
                }
                $paymentChannelAccount = $order->paymentChannelAccount;
                $gateway               = $paymentChannelAccount->paymentChannel->gateway;
                if (empty($gateway)) {
                    throw new Exception('支付网关获取异常，无法完成API自动退款');
                }

                $items = [
                    'order'         => $order->toArray(),
                    'channel'       => $paymentChannelAccount->config,
                    'refund_record' => $orderRefund->toArray(),
                ];

                $gatewayReturn = PaymentGatewayUtil::loadGateway($gateway, 'refund', $items);
                if ($gatewayReturn['state'] && !empty($gatewayReturn['api_refund_no'])) {
                    OrderRefund::where('id', $orderRefund->id)->update(['api_refund_no' => $gatewayReturn['api_refund_no']]);
                } else {
                    throw new Exception($gatewayReturn['message']);
                }
            }

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            return ['state' => false, 'msg' => $e->getMessage()];
        }
        return ['state' => true, 'refund_record' => $orderRefund->toArray(), 'gateway_return' => $gatewayReturn ?? []];
    }

    /**
     * 商户API发起退款
     *
     * 幂等规则：
     * - 若 out_biz_no 已存在，且 trade_no + amount 一致，则返回已有退款记录
     * - 若 out_biz_no 已存在，但参数不一致，则返回错误
     *
     * @param string      $tradeNo      平台订单号
     * @param string      $refundAmount 退款金额（字符串格式，保留2位小数）
     * @param string      $reason       退款原因
     * @param string|null $outBizNo     商户退款业务号（用于幂等）
     * @param int         $merchantId   商户ID
     * @return array
     */
    public static function apiRefund(string $tradeNo, string $refundAmount, string $reason = '商户发起退款', ?string $outBizNo = null, int $merchantId = 0): array
    {
        // 格式化金额
        $refundAmount = number_format((float)$refundAmount, 2, '.', '');

        // 幂等处理：检查业务号是否已存在
        if ($outBizNo !== null && $outBizNo !== '') {
            $existingRefund = OrderRefund::where('merchant_id', $merchantId)->where('out_biz_no', $outBizNo)->first(['id', 'trade_no', 'amount']);
            if ($existingRefund !== null) {
                // 校验参数一致性：订单号和金额必须相同
                if ($existingRefund->trade_no !== $tradeNo || bccomp((string)$existingRefund->amount, $refundAmount, 2) !== 0) {
                    return ['success' => false, 'message' => '商户退款业务号已存在，但订单号或金额不一致', 'refund_id' => null];
                }
                // 幂等返回已有记录

                return ['success' => true, 'message' => '退款成功', 'refund_id' => $existingRefund->id];
            }
        }

        // 验证订单归属
        $order = Order::where('trade_no', $tradeNo)->first(['merchant_id']);
        if ($order === null || $order->merchant_id !== $merchantId) {
            return ['success' => false, 'message' => '订单不存在或不属于当前商户', 'refund_id' => null];
        }

        // 执行退款
        $fee_bearer = sys_config('payment', 'api_refund_fee_bearer', 'merchant') === 'platform';
        $result     = self::handle($tradeNo, $refundAmount, 'api', true, $fee_bearer, $outBizNo, $reason);

        return $result['state'] ? ['success' => true, 'message' => '退款成功', 'refund_id' => $result['refund_record']['id']] : ['success' => false, 'message' => $result['msg'], 'refund_id' => null];
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
        // 参数校验
        if (bccomp($total_amount, '0', 2) <= 0 || bccomp($fee_amount, '0', 2) <= 0) {
            return '0.00';
        }

        // 计算退款比例 = 退款金额 / 订单金额
        $refund_ratio = bcdiv($refund_amount, $total_amount, 8);

        // 计算应退还的服务费 = 平台服务费 × 退款比例
        $refund_fee = bcmul($fee_amount, $refund_ratio, 8);

        // 保留两位小数并确保不超过原服务费
        $refund_fee = number_format($refund_fee, 2, '.', '');

        return bccomp($refund_fee, $fee_amount, 2) > 0 ? $fee_amount : $refund_fee;
    }
}
