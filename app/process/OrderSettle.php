<?php

declare(strict_types = 1);

namespace app\process;

use app\model\MerchantWalletRecord;
use app\model\Order;
use Carbon\Carbon;
use Workerman\Crontab\Crontab;

class OrderSettle
{
    public function onWorkerStart(): void
    {
        // 每5分钟的第8秒执行一次，重试近7天结算失败的订单
        new Crontab('8 */5 * * * *', function () {
            // 用 chunk 分批处理，每批 200 条数据
            Order::select(['trade_no', 'merchant_id', 'receipt_amount', 'settle_state'])->where([['settle_state', '=', Order::SETTLE_STATE_FAILED], ['create_time', '>=', Carbon::now()->timezone(config('app.default_timezone'))->subDays(7)]])->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    if (MerchantWalletRecord::change($row->merchant_id, $row->receipt_amount, '订单收益', true, $row->trade_no, '系统自动结算(失败重试)')) {
                        $row->settle_state = Order::SETTLE_STATE_COMPLETED;
                        $row->save();
                    }
                }
            });
        });
    }
}
