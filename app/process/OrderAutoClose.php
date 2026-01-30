<?php

declare(strict_types=1);

namespace app\process;

use app\model\Order;
use Carbon\Carbon;
use Core\Service\OrderService;
use support\Log;
use Throwable;
use Workerman\Crontab\Crontab;

/**
 * 订单自动关闭定时任务
 *
 * 每分钟检测已到关闭时间的待支付订单并自动关闭
 */
class OrderAutoClose
{
    /**
     * onWorkerStart 事件回调
     *
     * @return void
     */
    public function onWorkerStart(): void
    {
        // 每分钟的第1秒执行一次
        new Crontab('1 * * * * *', function () {
            $this->closeExpiredOrders();
        });
    }

    /**
     * 关闭过期订单
     *
     * @return void
     */
    private function closeExpiredOrders(): void
    {
        $now = Carbon::now();

        // 查询 close_time <= 当前时间 且 trade_state = WAIT_PAY 的订单
        $query = Order::where('trade_state', Order::TRADE_STATE_WAIT_PAY)->whereNotNull('close_time')->where('close_time', '<=', $now)->select(['trade_no', 'api_trade_no', 'payment_channel_account_id']);

        $closedCount = 0;
        $failedCount = 0;

        $query->chunkById(100, function ($orders) use (&$closedCount, &$failedCount) {
            foreach ($orders as $order) {
                try {
                    // 如果有上游订单号则尝试调用网关关闭
                    $callGateway = !empty($order->api_trade_no);
                    $result      = OrderService::handleOrderClose($order->trade_no, $callGateway);

                    if ($result['state']) {
                        $closedCount++;
                    } else {
                        $failedCount++;
                        Log::channel('process')->warning("自动关闭订单失败：{$order->trade_no}，原因：{$result['message']}");
                    }
                } catch (Throwable $e) {
                    $failedCount++;
                    Log::channel('process')->error("自动关闭订单异常：{$order->trade_no}，错误：{$e->getMessage()}");
                }
            }
        }, 'trade_no');

        if ($closedCount > 0 || $failedCount > 0) {
            Log::channel('process')->info("订单自动关闭任务执行完成，成功：{$closedCount}，失败：$failedCount");
        }
    }
}
