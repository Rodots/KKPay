<?php

declare(strict_types=1);

namespace app\process;

use Core\Service\ComplaintService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 投诉自动抓取定时任务
 *
 * 每10分钟从已配置的支付通道子账户自动拉取最新投诉列表，
 * 新投诉入库后自动匹配订单并拉黑关联的付款人。
 */
class ComplaintAutoFetch
{
    /**
     * onWorkerStart 事件回调
     *
     * 注册定时任务：每10分钟执行一次投诉抓取。
     *
     * @return void
     */
    public function onWorkerStart(): void
    {
        // 每10分钟执行一次（第0秒，每10分钟）
        new Crontab('0 */10 * * * *', function () {
            $accountIds = ComplaintService::getAutoFetchAccounts();
            if (empty($accountIds)) {
                return;
            }

            Log::channel('process')->info('开始执行投诉自动抓取任务，子账户数量: ' . count($accountIds));

            $totalCount = 0;
            foreach ($accountIds as $accountId) {
                $count      = ComplaintService::fetchAndSaveComplaints((int)$accountId);
                $totalCount += $count;
                if ($count > 0) {
                    Log::channel('process')->info("子账户[$accountId]新增投诉 $count 条");
                }
            }

            Log::channel('process')->info("投诉自动抓取任务完成，共新增 $totalCount 条投诉记录");
        });
    }
}
