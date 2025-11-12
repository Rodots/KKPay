<?php

declare(strict_types = 1);

namespace app\api\controller;

use app\model\Order;
use app\model\PaymentChannel;
use Core\Exception\PaymentException;
use Core\Service\PaymentService;
use Core\Traits\ApiResponse;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 收银台控制器
 * 处理收银台页面展示和支付方式选择
 */
class CheckoutController
{
    use ApiResponse;

    /**
     * 收银台页面
     * 路由：/checkout/{order_no}
     */
    public function index(Request $request, string $orderNo): Response
    {
        try {
            if (empty($orderNo)) {
                return $this->pageMsg('订单号不能为空');
            }

            // 查找订单
            $order = Order::where('trade_no', $orderNo)->first();
            if (!$order) {
                return $this->pageMsg('订单不存在');
            }

            // 检查订单状态
            if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY) {
                return $this->pageMsg('订单状态异常，无法支付');
            }

            // 检查订单是否已过期（30分钟）
            if (strtotime($order->create_time) < time() - 1800) {
                return $this->pageMsg('订单已过期');
            }

            // 获取可用的支付方式
            $paymentTypes = $this->getAvailablePaymentTypes((float)$order->total_amount);

            // 渲染收银台页面
            return raw_view("/app/api/view/checkout", ['order' => $order->toArray(), 'paymentTypesJson' => json_encode($paymentTypes, JSON_UNESCAPED_UNICODE)]);

        } catch (Throwable $e) {
            Log::error('收银台页面加载异常', [
                'order_no' => $orderNo,
                'error'    => $e->getMessage(),
                'ip'       => $request->getRealIp()
            ]);
            return $this->pageMsg('页面加载失败，请稍后重试');
        }
    }

    /**
     * 选择支付方式
     * 处理用户在收银台选择的支付方式
     */
    public function selectPayment(Request $request): Response
    {
        try {
            $orderNo            = $request->route('order_no');
            $paymentType        = $request->post('payment_type');
            $paymentChannelCode = $request->post('payment_channel_code');

            if (empty($orderNo)) {
                return $this->fail('订单号不能为空');
            }

            if (empty($paymentType)) {
                return $this->fail('请选择支付方式');
            }

            // 查找订单
            $order = Order::where('trade_no', $orderNo)->first();
            if (!$order) {
                return $this->fail('订单不存在');
            }

            // 检查订单状态
            if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY) {
                return $this->fail('订单状态异常，无法支付');
            }

            // 检查订单是否已过期
            if (strtotime($order->create_time) < time() - 1800) {
                return $this->fail('订单已过期');
            }

            // 验证支付方式
            if (!Order::checkPaymentType($paymentType)) {
                return $this->fail('不支持的支付方式');
            }

            // 更新订单支付方式和通道
            $this->updateOrderPaymentInfo($order, $paymentType, $paymentChannelCode);

            // 发起支付
            $paymentResult = PaymentService::initiatePayment($order);

            // 返回支付结果
            $responseData = [
                'trade_no'     => $order->trade_no,
                'payment_type' => $order->payment_type,
                'order_status' => $order->trade_state,
            ];

            if (!empty($paymentResult['qr_code'])) {
                $responseData['qr_code']        = $paymentResult['qr_code'];
                $responseData['payment_method'] = 'qr_code';
            } elseif (!empty($paymentResult['payment_url'])) {
                $responseData['payment_url']    = $paymentResult['payment_url'];
                $responseData['payment_method'] = 'redirect';
            } elseif (!empty($paymentResult['form_data'])) {
                $responseData['form_data']      = $paymentResult['form_data'];
                $responseData['payment_method'] = 'form';
            }

            return $this->success($responseData, '支付发起成功');

        } catch (PaymentException $e) {
            Log::warning('收银台支付选择失败', [
                'order_no'     => $orderNo ?? '',
                'payment_type' => $paymentType ?? '',
                'error'        => $e->getMessage(),
                'ip'           => $request->getRealIp()
            ]);
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::error('收银台支付选择异常', [
                'order_no'     => $orderNo ?? '',
                'payment_type' => $paymentType ?? '',
                'error'        => $e->getMessage(),
                'ip'           => $request->getRealIp()
            ]);
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 获取可用的支付方式
     */
    private function getAvailablePaymentTypes(float $amount): array
    {
        $paymentChannels = PaymentChannel::where('status', true)
            ->where(function ($query) use ($amount) {
                $query->where(function ($q) use ($amount) {
                    $q->whereNull('min_amount')->orWhere('min_amount', '<=', $amount);
                })->where(function ($q) use ($amount) {
                    $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount);
                });
            })
            ->get();

        $paymentTypes = [];
        foreach ($paymentChannels as $channel) {
            $paymentTypes[$channel->payment_type] = [
                'type'     => $channel->payment_type,
                'name'     => $channel->payment_type_text,
                'channels' => []
            ];
        }

        // 添加通道信息
        foreach ($paymentChannels as $channel) {
            $paymentTypes[$channel->payment_type]['channels'][] = [
                'code'       => $channel->code,
                'name'       => $channel->name,
                'rate'       => $channel->rate,
                'min_amount' => $channel->min_amount,
                'max_amount' => $channel->max_amount,
            ];
        }

        return array_values($paymentTypes);
    }

    /**
     * 更新订单支付信息
     */
    private function updateOrderPaymentInfo(Order $order, string $paymentType, ?string $paymentChannelCode): void
    {
        // 选择支付通道账户
        if (!empty($paymentChannelCode)) {
            $paymentChannelAccount = PaymentService::selectPaymentChannelByCode(
                $paymentChannelCode,
                $paymentType,
                $order->total_amount
            );
        } else {
            $paymentChannelAccount = PaymentService::selectPaymentChannelByType(
                $paymentType,
                $order->total_amount
            );
        }

        // 更新订单
        $order->payment_type               = $paymentType;
        $order->payment_channel_account_id = $paymentChannelAccount->id;
        $order->save();
    }
}
