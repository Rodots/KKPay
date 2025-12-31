<?php

declare(strict_types=1);

namespace app\queue\redis;

use app\model\MerchantEncryption;
use app\model\Order;
use app\model\OrderNotification as OrderNotificationModel;
use Core\Utils\ProxyHelper;
use Core\Utils\SignatureUtil;
use GuzzleHttp\Client;
use support\Rodots\Functions\Uuid;
use Throwable;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis as SyncQueue;

class OrderNotification implements Consumer
{
    public string $queue      = 'order-notification';
    public string $connection = 'default';

    // 重试间隔时间（秒）
    private const array RETRY_INTERVALS = [
        10, // 10秒
        20, // 30秒
        30, // 1分钟
        60, // 2分钟
        60, // 3分钟
        120 // 5分钟
    ];

    public function consume($data): void
    {
        if (!isset($data['trade_no'])) {
            return;
        }

        $tradeNo = $data['trade_no'];
        $order   = Order::where([['trade_no', '=', $tradeNo], ['notify_retry_count', '<', 7]])->whereIn('notify_state', [Order::NOTIFY_STATE_WAITING, Order::NOTIFY_STATE_FAILED])->lockForUpdate()->first(['trade_no', 'merchant_id', 'notify_url', 'notify_state', 'notify_retry_count', 'notify_next_retry_time']);

        if (!$order) {
            return;
        }

        // 检查是否到达重试时间，如果未到时间则重新加入队列
        $now_time = time();
        if ($order->notify_next_retry_time && $order->notify_next_retry_time > $now_time) {
            $delay = max(1, $order->notify_next_retry_time - $now_time);
            SyncQueue::send($this->queue, $data, $delay);
            return;
        }

        // 执行通知
        $notificationResult = $this->sendNotification($data, $order->merchant_id, $order->notify_url);
        $isSuccess          = $notificationResult['result'] === 'success';
        $requestDuration    = $notificationResult['duration'];

        // 立即重试逻辑：如果首次失败，立即再试一次
        if (!$isSuccess && $order->notify_retry_count === 0) {
            $notificationResult = $this->sendNotification($data, $order->merchant_id, $order->notify_url);
            $isSuccess          = $notificationResult['result'] === 'success';
            $requestDuration    = $notificationResult['duration']; // 更新为第二次请求的耗时
        }

        // 更新重试次数和下次重试时间
        if ($isSuccess) {
            $order->notify_state           = Order::NOTIFY_STATE_SUCCESS;
            $order->notify_next_retry_time = null;
        } else {
            $order->notify_state = Order::NOTIFY_STATE_FAILED;
            $order->notify_retry_count++;

            if ($order->notify_retry_count < 7) {
                $baseDelay = self::RETRY_INTERVALS[$order->notify_retry_count - 1];
                // 计算实际延迟时间，扣除本次请求耗时
                $actualDelay                   = max(1, $baseDelay - $requestDuration);
                $order->notify_next_retry_time = $now_time + $actualDelay;

                // 重新加入队列等待下次重试
                SyncQueue::send($this->queue, $data, $actualDelay);
            } else {
                // 超过最大重试次数
                $order->notify_next_retry_time = null;
            }
        }

        $order->save();
    }

    /**
     * 发送通知
     */
    private function sendNotification(array $data, int $merchant_id, string $notify_url): array
    {
        $notification     = new OrderNotificationModel();
        $notification->id = Uuid::v7();

        // 在发送时生成实时时间戳和签名
        $fullData = array_merge($data, ['timestamp' => time()]);

        $signKey = $data['sign_type'] === MerchantEncryption::SIGN_TYPE_SHA256withRSA ? config('kkpay.payment_rsa2_private_key', '') : MerchantEncryption::where('merchant_id', $merchant_id)->value('hash_key');

        $signatureResult  = SignatureUtil::buildSignature($fullData, $data['sign_type'], $signKey);
        $fullData['sign'] = $signatureResult['sign'];

        $headers = ['Notification-Type' => 'trade_state_sync', 'Notification-Id' => $notification->id];

        $startTime = microtime(true);
        $response  = $this->sendHttp($notify_url, $fullData, $headers);
        $duration  = (int)((microtime(true) - $startTime) * 1000); // 计算请求耗时（毫秒）

        $notification->trade_no         = $data['trade_no'];
        $notification->status           = $response === 'success';
        $notification->request_duration = $duration;
        $notification->response_content = mb_substr($response, 0, 2048, 'utf-8');
        $notification->save();

        return [
            'result'   => $response,
            'duration' => $duration
        ];
    }

    /**
     * 发起 HTTP 请求
     */
    private function sendHttp(string $url, array $params = [], array $headers = []): string
    {
        $clientConfig = array_merge(['timeout' => 8], ProxyHelper::getGuzzleProxyConfig());
        $client       = new Client($clientConfig);

        try {
            $response = $client->request('POST', $url, [
                'json'    => $params,
                'headers' => array_merge($headers, [
                    'Accept'        => 'text/plain', // 期望纯文本响应
                    'Cache-Control' => 'no-cache',
                    'User-Agent'    => 'Payment Order Notification Client/1.0'
                ]),
            ]);

            return trim($response->getBody()->getContents());
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
