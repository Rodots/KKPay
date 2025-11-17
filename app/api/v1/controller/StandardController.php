<?php

declare(strict_types = 1);

namespace app\api\v1\controller;

use app\model\Order;
use Core\Service\OrderService;
use Exception;
use support\Db;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class StandardController
{
    #[RateLimiter(limit: 1, ttl: 3, key: RateLimiter::SID, message: '状态查询频率过快，别急')]
    public function queryQRStatus(Request $request): Response
    {
        $trade_no = $request->get('trade_no');
        // 校验请求
        if ($request->expectsJson() && $request->method() === 'GET' && $request->host(true) === parse_url($request->header('referer'))['host'] && $trade_no !== null) {
            if (preg_match('/^P\d{18}[A-Z]{5}$/', $trade_no)) {
                $order = Db::table('order')->select(['trade_no', 'out_trade_no', 'bill_trade_no', 'total_amount', 'attach', 'trade_state', 'return_url', 'create_time', 'payment_time'])->where('trade_no', $trade_no)->first();
                if ($order !== null && $order->trade_state !== Order::TRADE_STATE_WAIT_PAY) {
                    if ($order->trade_state === Order::TRADE_STATE_SUCCESS || $order->trade_state === Order::TRADE_STATE_FROZEN) {
                        // 支付完成5分钟后禁止跳转回网站
                        if ($order->payment_time !== null && time() - strtotime($order->payment_time) > 300) {
                            $redirect_url = '/payok.html';
                        } else {
                            try {
                                $redirect_url = OrderService::buildSyncNotificationParams((array)$order);
                            } catch (Exception) {
                                $redirect_url = $order->return_url;
                            }
                        }
                        $result = [
                            'code'    => 20000,
                            'data'    => [
                                'redirect_url' => $redirect_url
                            ],
                            'message' => '付款成功',
                            'state'   => true
                        ];
                    } else {
                        $result = [
                            'code'    => 40423,
                            'data'    => [
                                'redirect_url' => '/payfail.html'
                            ],
                            'message' => '交易已结束',
                            'state'   => true
                        ];
                    }

                    return new Response(200, ['Content-Type' => 'application/json'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                $result = [
                    'code'    => 20000,
                    'data'    => [],
                    'message' => '订单不存在或未支付',
                    'state'   => false
                ];

                return new Response(200, ['Content-Type' => 'application/json'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        return new Response(400);
    }
}
