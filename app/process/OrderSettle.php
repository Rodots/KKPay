<?php

declare(strict_types = 1);

namespace app\process;

use Core\Service\OrderService;
use Workerman\Crontab\Crontab;

/**
 * 订单结算定时任务
 */
class OrderSettle
{
    /**
     * onWorkerStart 事件回调
     *
     * 设置一个定时器，每6个小时的第6分钟的第6秒执行一次，
     * 用于重试近7天内结算失败的订单。
     *
     * @return void
     */
    public function onWorkerStart(): void
    {
        // 每6个小时的第6分钟的第6秒执行一次，尝试重新投递近7天结算失败的订单
        new Crontab('6 6 */6 * * *', function () {
            OrderService::retryFailedSettlements();
        });
    }
}
