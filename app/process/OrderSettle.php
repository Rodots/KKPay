<?php

declare(strict_types = 1);

namespace app\process;

use app\model\MerchantWalletRecord;
use app\model\Order;
use Carbon\Carbon;
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

            // 用 chunk 分批处理，每批 200 条数据
            Order::select(['trade_no', 'merchant_id', 'receipt_amount', 'settle_state', 'settle_cycle', 'payment_time'])->where([['settle_state', '=', Order::SETTLE_STATE_FAILED], ['create_time', '>=', $now->copy()->subDays(7)]])->chunkById(200, function ($rows) use ($now) {
                foreach ($rows as $row) {
                    // 计算原本应该结算的时间
                    $originalSettleTime = Carbon::Parse($row->getOriginal('payment_time'))->addDays($row->settle_cycle);

                    if ($now->gte($originalSettleTime)) {
                        // 当前时间已经超过原本该结算的时间，立即执行结算任务
                        try {
                            MerchantWalletRecord::changeAvailable($row->merchant_id, $row->receipt_amount, '订单收益', true, $row->trade_no, '自动结算(失败重试)', true);
                            $row->settle_state = Order::SETTLE_STATE_COMPLETED;
                            $row->save();
                        } catch (Throwable) {
                            continue;
                        }
                    } else {
                        // 计算从当前时间到原本结算时间的时间差（秒数）
                        $delay = (int)$now->diffInSeconds($originalSettleTime, true);

                        // 尝试将结算任务重新投递到队列
                        if (SyncQueue::send('order-settle', $row->trade_no, $delay)) {
                            // 投递成功，更新结算状态为处理中
                            $row->settle_state = Order::SETTLE_STATE_PROCESSING;
                            $row->save();
                        }
                        // 如果投递失败($result为false)，跳过当前订单，等待下次计划任务运行
                    }
                }
            }, 'trade_no');
        });
    }
}
