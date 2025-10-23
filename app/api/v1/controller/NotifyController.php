<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\Order;
use Core\Gateway\GatewayManager;
use Core\Exception\PaymentException;
use support\Request;
use support\Response;
use support\Log;
use Throwable;

/**
 * 异步通知控制器
 * 统一处理各支付渠道的异步通知回调
 */
class NotifyController
{
    /**
     * 统一异步通知处理
     * 路由：/api/notify/{order_no}
     */
    public function handle(Request $request, string $orderNo): Response
    {
        try {
            Log::info('收到异步通知', [
                'order_no' => $orderNo,
                'ip' => $request->getRealIp(),
                'user_agent' => $request->header('User-Agent', ''),
                'post_data' => $request->post(),
                'raw_body' => $request->rawBody()
            ]);

            // 查找订单
            $order = Order::where('trade_no', $orderNo)->first();
            if (!$order) {
                Log::warning('异步通知订单不存在', ['order_no' => $orderNo]);
                return response('ORDER_NOT_FOUND', 404);
            }

            // 获取支付通道网关
            if (!$order->paymentChannelAccount || !$order->paymentChannelAccount->paymentChannel) {
                Log::error('订单未绑定支付通道', ['order_no' => $orderNo]);
                return response('CHANNEL_NOT_FOUND', 400);
            }

            $gateway = $order->paymentChannelAccount->paymentChannel->gateway;
            
            // 准备通知数据
            $notifyData = $request->post();
            if (empty($notifyData)) {
                // 如果POST为空，使用原始数据
                $rawBody = $request->rawBody();
                if (!empty($rawBody)) {
                    $notifyData = ['raw_body' => $rawBody];
                }
            }

            // 调用网关的notify方法处理通知
            $result = GatewayManager::callGatewayMethod($gateway, 'notify', $notifyData);

            if ($result['success']) {
                // 更新订单状态
                $this->updateOrderFromNotify($order, $result);
                
                Log::info('异步通知处理成功', [
                    'order_no' => $orderNo,
                    'gateway' => $gateway,
                    'trade_status' => $result['trade_status'] ?? ''
                ]);

                // 返回网关期望的响应格式
            } else {
                Log::error('异步通知处理失败', [
                    'order_no' => $orderNo,
                    'gateway' => $gateway,
                    'error' => $result['message'] ?? '未知错误'
                ]);

            }
            return $this->buildNotifyResponse($result);

        } catch (PaymentException $e) {
            Log::error('异步通知处理异常', [
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            return response('PAYMENT_ERROR', 500);
        } catch (Throwable $e) {
            Log::error('异步通知系统异常', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return response('SYSTEM_ERROR', 500);
        }
    }



    /**
     * 从异步通知更新订单状态
     */
    private function updateOrderFromNotify(Order $order, array $notifyResult): void
    {
        $tradeStatus = $notifyResult['trade_status'] ?? '';
        
        // 根据不同的交易状态更新订单
        $newStatus = match ($tradeStatus) {
            'TRADE_SUCCESS', 'TRADE_FINISHED', 'SUCCESS' => Order::TRADE_STATE_SUCCESS,
            'TRADE_CLOSED', 'CLOSED' => Order::TRADE_STATE_CLOSED,
            'WAIT_PAY', 'NOTPAY' => Order::TRADE_STATE_WAIT_PAY,
            default => $order->trade_state
        };

        if ($newStatus !== $order->trade_state) {
            $updateData = [
                'trade_state' => $newStatus,
                'api_trade_no' => $notifyResult['api_trade_no'] ?? $order->api_trade_no,
            ];

            if ($newStatus === Order::TRADE_STATE_SUCCESS) {
                $updateData['buyer_pay_amount'] = $notifyResult['buyer_pay_amount'] ?? $order->total_amount;
            }

            $order->update($updateData);

            Log::info('订单状态更新', [
                'order_no' => $order->trade_no,
                'old_status' => $order->trade_state,
                'new_status' => $newStatus,
                'api_trade_no' => $notifyResult['api_trade_no'] ?? ''
            ]);
        }
    }

    /**
     * 构建通知响应
     */
    private function buildNotifyResponse(array $notifyResult): Response
    {
        // 使用网关返回的响应内容
        $response = $notifyResult['response'] ?? ($notifyResult['success'] ? 'success' : 'fail');
        
        return response($response, 200, [
            'Content-Type' => 'text/plain'
        ]);
    }
}
