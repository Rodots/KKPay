<?php

declare(strict_types=1);

namespace app\process;

use app\model\Merchant;
use app\model\MerchantPayee;
use Core\Service\MerchantWithdrawalService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 商户自动清账定时任务
 *
 * 每日 00:00:00 自动为拥有 autoWithdraw 权限的商户执行清账操作
 */
class MerchantAutoWithdraw
{
    /**
     * onWorkerStart 事件回调
     *
     * 设置定时器，每日凌晨00:00:00执行自动清账
     *
     * @return void
     */
    public function onWorkerStart(): void
    {
        // 每日00:00:00执行自动清账
        new Crontab('0 0 0 * * *', function () {
            Log::channel('process')->info('开始执行商户自动清账任务');

            // 查询所有具有 autoWithdraw 权限的商户
            $merchants = Merchant::whereJsonContains('competence', 'autoWithdraw')->where('status', true)->get(['id', 'merchant_number']);

            $successCount = 0;
            $failCount    = 0;

            foreach ($merchants as $merchant) {
                // 获取该商户的默认收款人
                $payee = MerchantPayee::where('merchant_id', $merchant->id)->where('is_default', true)->first();

                if (!$payee) {
                    Log::channel('process')->warning("商户【{$merchant->merchant_number}】没有默认收款人，跳过自动清账");
                    $failCount++;
                    continue;
                }

                // 调用清账服务
                $result = MerchantWithdrawalService::settleAccount($merchant->id, $payee->id);

                if ($result['success']) {
                    Log::channel('process')->info("商户【{$merchant->merchant_number}】自动清账成功：{$result['message']}");
                    $successCount++;
                } else {
                    Log::channel('process')->warning("商户【{$merchant->merchant_number}】自动清账失败：{$result['message']}");
                    $failCount++;
                }
            }

            Log::channel('process')->info("商户自动清账任务完成：成功 $successCount 个，失败 $failCount 个");
        });
    }
}
