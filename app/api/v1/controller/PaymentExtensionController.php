<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\Merchant;
use app\model\Order;
use app\model\OrderBuyer;
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

            // 处理自定义商品名称变量替换
            $subject  = $order->subject;
            $merchant = Merchant::where('id', $order->merchant_id)->first(['diy_order_subject', 'email', 'mobile']);
            if ($merchant && !empty($merchant->diy_order_subject)) {
                $subject = str_replace(
                    ['[name]', '[order]', '[outorder]', '[time]', '[email]', '[mobile]'],
                    [$order->subject, $order->trade_no, $order->out_trade_no, (string)time(), $merchant->email ?? '', $merchant->mobile ?? ''],
                    $merchant->diy_order_subject
                );
            }

            $notify_url = sys_config('system', 'notify_url');

            $items = [
                'isExtension' => true,
                'order'       => $orderData,
                'channel'     => $paymentChannelAccount->config,
                'buyer'       => OrderBuyer::where('trade_no', $order->trade_no)->first(['ip', 'user_agent', 'user_id', 'buyer_open_id', 'real_name', 'cert_no', 'cert_type', 'min_age', 'mobile']),
                'subject'     => $subject,
                'return_url'  => site_url("pay/return/$order->trade_no.html"),
                'notify_url'  => empty($notify_url) ? site_url("pay/notify/$order->trade_no.html") : $notify_url . "pay/notify/$order->trade_no.html",
            ];
            unset($order);

            $gateway = $paymentChannelAccount->paymentChannel->gateway;

            $paymentResult = PaymentGatewayUtil::loadGateway($gateway, $method, $items);

            return PaymentService::echoPage($paymentResult, $orderData);
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
