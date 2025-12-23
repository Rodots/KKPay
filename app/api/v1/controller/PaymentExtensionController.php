<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\Order;
use Core\Service\PaymentService;
use Core\Traits\ApiResponse;
use Core\Utils\PaymentGatewayUtil;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 支付网关扩展方法控制器
 *
 * 处理 /pay/{method}/{orderNo}.html 格式的请求
 * 无需签名验证，用于处理支付网关回调等场景
 */
class PaymentExtensionController
{
    use ApiResponse;

    /**
     * 处理网关扩展方法调用
     *
     * 路由格式: /pay/{method}/{orderNo}.html
     * 支持的方法: notify（异步回调）、return（同步回调）、qrcode（二维码展示）等
     *
     * @param Request $request 请求对象
     * @return Response 响应
     */
    public function handle(Request $request): Response
    {
        $method  = $request->route->param('method');
        $orderNo = $request->route->param('orderNo');

        $echoMethod = ($method === 'notify') ? 'textMsg' : 'pageMsg';

        if (empty($method) || empty($orderNo)) {
            return $this->$echoMethod('参数错误');
        }

        if ($method === 'notify') {
            Log::channel('pay_notify')->info('orderNo: ' . $orderNo, $request->all());
        } elseif ($method === 'refund' || $method === 'close') {
            return $this->$echoMethod('参数错误');
        }

        try {
            // 预加载 paymentChannelAccount 及其关联的 paymentChannel（仅需 gateway 字段）
            $order = Order::with([
                'paymentChannelAccount' => function ($query) {
                    $query->select(['id', 'payment_channel_id', 'config'])->with('paymentChannel:id,gateway');
                }
            ])->where('trade_no', $orderNo)->first();

            if ($order === null) {
                return $this->$echoMethod('订单不存在');
            }

            // 检查订单是否可以支付
            if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY || $order->trade_state === Order::TRADE_STATE_CLOSED) {
                return $this->$echoMethod('当前订单已交易结束');
            }

            $paymentChannelAccount = $order->paymentChannelAccount;
            if ($paymentChannelAccount === null) {
                return redirect("/checkout/$order->trade_no.html");
            }

            // 构建纯净订单数据
            $orderData = $order->toArray();
            unset($orderData['payment_channel_account']);

            $items = [
                'order'      => $orderData,
                'channel'    => $paymentChannelAccount->config,
                'buyer'      => $order->buyer->toArray(),
                'subject'    => $order->subject,
                'return_url' => site_url("pay/return/$order->trade_no.html"),
                'notify_url' => site_url("pay/notify/$order->trade_no.html"),
            ];
            unset($order);

            $gateway = $paymentChannelAccount->paymentChannel->gateway;

            $paymentResult = PaymentGatewayUtil::loadGateway($gateway, $method, $items);

            return PaymentService::echoSubmit($paymentResult, $orderData);
        } catch (Throwable $e) {
            Log::error('支付拓展方法加载异常', [
                'method'   => $method,
                'order_no' => $orderNo,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return $this->$echoMethod('系统异常，请稍后重试');
        }
    }
}
