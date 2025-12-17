<?php

declare(strict_types = 1);

namespace app\process;

use app\model\MerchantWalletRecord;
use app\model\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use Webman\RedisQueue\Redis as SyncQueue;
use Workerman\Crontab\Crontab;

class OrderSettle
{
    public function onWorkerStart(): void
    {
        // 每8个小时的第8分钟的第8秒执行一次，尝试重新投递近7天结算失败的订单（只有延迟结算的订单才有可能失败，所以频率无需过高）
        new Crontab('8 8 */8 * * *', function () {
            $now = Carbon::now()->timezone(config('app.default_timezone'));
            Order::whereHas('merchant', function (Builder $query) {
                $query->whereJsonContains('competence', 'settle');
            })
                ->select(['trade_no', 'merchant_id', 'receipt_amount', 'settle_state', 'settle_cycle', 'payment_time'])
                ->where('settle_state', '=', Order::SETTLE_STATE_FAILED)
                ->where('create_time', '>=', $now->copy()->subDays(7))
                ->whereIn('trade_state', [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_FINISHED])
                ->chunkById(200, function ($rows) use ($now) {
                    foreach ($rows as $row) {
                        // 计算原本应该结算的时间
                        $originalSettleTime = Carbon::parse($row->getOriginal('payment_time'))->addDays($row->settle_cycle);

                        if ($now->gte($originalSettleTime)) {
                            // 立即执行结算
                            try {
                                MerchantWalletRecord::changeAvailable($row->merchant_id, $row->receipt_amount, '订单收益', true, $row->trade_no, '自动结算(失败重试)', true);
                                $row->settle_state = Order::SETTLE_STATE_COMPLETED;
                                $row->save();
                            } catch (Throwable) {
                                continue;
                            }
                        } else {
                            // 重新投递到队列
                            $delay = (int)$now->diffInSeconds($originalSettleTime, true);
                            if (SyncQueue::send('order-settle', $row->trade_no, $delay)) {
                                $row->settle_state = Order::SETTLE_STATE_PROCESSING;
                                $row->save();
                            }
                        }
                    }
                }, 'trade_no');
        });
    }
}
