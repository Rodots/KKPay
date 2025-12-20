<?php

declare(strict_types=1);

namespace app\queue\redis;

use app\model\Order;
use app\model\OrderNotification as OrderNotificationModel;
use GuzzleHttp\Client;
use support\Rodots\Functions\Uuid;
use Throwable;
use Webman\RedisQueue\Consumer;

class OrderNotificationManual implements Consumer
{
    public string $queue      = 'order-notification-manual';
    public string $connection = 'default';

    public function consume($data): void
    {
        if (!isset($data['params']['trade_no'])) {
            return;
        }
        if (!$order = Order::where('trade_no', $data['params']['trade_no'])->lockForUpdate()->first(['trade_no', 'notify_url', 'notify_state', 'notify_next_retry_time'])) {
            return;
        }

        // 执行通知
        $isSuccess = $this->sendNotification($data, $order->notify_url) === 'success';

        // 更新状态
        if ($isSuccess) {
            $order->notify_state           = Order::NOTIFY_STATE_SUCCESS;
            $order->notify_next_retry_time = null;
        } else {
            $order->notify_state = Order::NOTIFY_STATE_FAILED;
        }
        $order->save();
    }

    /**
     * 发送通知
     */
    private function sendNotification(array $data, string $url): string
    {
        $notification           = new OrderNotificationModel();
        $notification->id       = Uuid::v7();
        $notification->trade_no = $data['params']['trade_no'];

        $startTime = microtime(true); // 记录开始时间

        $headers  = ['Notification-Type' => 'trade_state_sync', 'Notification-Id' => $notification->id, 'Notification-SignatureString' => $data['sign_string']];
        $response = $this->sendHttp($url, $data['params'], $headers);

        $duration = (int)((microtime(true) - $startTime) * 1000); // 计算请求耗时（毫秒）

        $notification->status           = $response === 'success';
        $notification->request_duration = $duration;
        $notification->response_content = mb_substr($response, 0, 2048, 'utf-8');
        $notification->save();

        return $response;
    }

    /**
     * 发起 HTTP 请求
     */
    private function sendHttp(string $url, array $params = [], array $headers = []): string
    {
        $client = new Client([
            'timeout' => 8
        ]);

        try {
            $response = $client->request('POST', $url, [
                'json'    => $params,
                'headers' => array_merge($headers, [
                    'Accept'        => 'text/plain', // 期望纯文本响应
                    'Cache-Control' => 'no-cache',
                    'User-Agent'    => 'Payment Order Notification Manual Client/1.0'
                ]),
            ]);

            return trim($response->getBody()->getContents());
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
