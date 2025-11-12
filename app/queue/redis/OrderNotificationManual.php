<?php

declare(strict_types = 1);

namespace app\queue\redis;

use app\model\Order;
use app\model\OrderNotification as OrderNotificationModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use support\Rodots\Functions\Uuid;
use Throwable;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis as SyncQueue;

class OrderNotificationManual implements Consumer
{
    public string $queue      = 'order-notification-manual';
    public string $connection = 'default';

    public function consume($data): void
    {
        if (!isset($data['trade_no'])) {
            return;
        }

        $tradeNo = $data['trade_no'];
        $order   = Order::select(['trade_no', 'notify_url', 'notify_state', 'notify_next_retry_time'])->where('trade_no', $tradeNo)->lockForUpdate()->first();

        if (!$order) {
            return;
        }

        // 执行通知
        $isSuccess = $this->sendNotification($tradeNo, $order->notify_url, $data) === 'success';

        // 更新状态
        if ($isSuccess) {
            $order->notify_state           = true;
            $order->notify_next_retry_time = null;
            $order->save();
        }
    }

    /**
     * 发送通知
     */
    private function sendNotification(string $tradeNo, string $url, array $params): string
    {
        $notification           = new OrderNotificationModel();
        $notification->id       = Uuid::v7();
        $notification->trade_no = $tradeNo;

        $headers  = ['Notification-Id' => $notification->id];
        $response = $this->sendHttp($url, $params, $headers);

        $notification->status           = ($response === 'success') ? 1 : 0;
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
            'timeout'         => 10,
            'connect_timeout' => 3,
            'read_timeout'    => 7
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
        } catch (ClientException $e) {
            $msg = sprintf('HTTP %d: %s', $e->getCode(), $e->getResponse()->getBody());
            return "ClientError: $msg";
        } catch (ServerException $e) {
            return "ServerError ({$e->getCode()}): " . $e->getMessage();
        } catch (RequestException $e) {
            return "NetworkError: " . ($e->getMessage() ?: 'Unknown network issue');
        } catch (GuzzleException $e) {
            return "GuzzleError: " . $e->getMessage();
        } catch (Throwable $e) {
            return "SystemError: " . $e->getMessage();
        }
    }
}
