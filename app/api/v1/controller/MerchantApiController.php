<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\Order;
use app\model\MerchantWallet;
use Carbon\Carbon;
use Core\baseController\ApiBase;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户API控制器
 *
 * 提供商户信息查询、余额查询等API接口
 */
class MerchantApiController extends ApiBase
{
    /**
     * 商户信息查询接口
     *
     * 返回商户实时余额、今日/昨日流水等信息
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function info(Request $request): Response
    {
        try {
            $merchantId = $this->getMerchantId($request);

            // 获取钱包信息
            $wallet = MerchantWallet::where('merchant_id', $merchantId)->first(['available_balance', 'unavailable_balance', 'margin', 'prepaid']);

            // 获取今日统计
            $todayStart = Carbon::today()->format('Y-m-d H:i:s');
            $todayStats = $this->getOrderStats($merchantId, $todayStart);

            // 获取昨日统计
            $yesterdayStart = Carbon::yesterday()->format('Y-m-d H:i:s');
            $yesterdayEnd   = Carbon::today()->format('Y-m-d H:i:s');
            $yesterdayStats = $this->getOrderStats($merchantId, $yesterdayStart, $yesterdayEnd);

            return $this->success([
                'merchant_number' => $this->getMerchantNumber($request),
                'wallet'          => [
                    'available_balance'   => $wallet->available_balance ?? '0.00',
                    'unavailable_balance' => $wallet->unavailable_balance ?? '0.00',
                    'margin'              => $wallet->margin ?? '0.00',
                    'prepaid'             => $wallet->prepaid ?? '0.00',
                ],
                'today'           => $todayStats,
                'yesterday'       => $yesterdayStats,
            ], '查询成功');
        } catch (Throwable $e) {
            Log::error('商户信息查询异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 余额查询接口
     *
     * @param Request $request 请求对象
     * @return Response JSON响应
     */
    public function balance(Request $request): Response
    {
        try {
            $wallet = MerchantWallet::where('merchant_id', $this->getMerchantId($request))->first(['available_balance', 'unavailable_balance', 'margin', 'prepaid']);

            return $this->success([
                'available_balance'   => $wallet->available_balance ?? '0.00',
                'unavailable_balance' => $wallet->unavailable_balance ?? '0.00',
                'margin'              => $wallet->margin ?? '0.00',
                'prepaid'             => $wallet->prepaid ?? '0.00',
            ], '查询成功');
        } catch (Throwable $e) {
            Log::error('余额查询异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 获取订单统计数据
     *
     * @param int         $merchantId 商户ID
     * @param string      $startTime  开始时间
     * @param string|null $endTime    结束时间（可选）
     * @return array 统计数据
     */
    private function getOrderStats(int $merchantId, string $startTime, ?string $endTime = null): array
    {
        $query = Order::where('merchant_id', $merchantId)->where('create_time', '>=', $startTime);
        if ($endTime !== null) {
            $query->where('create_time', '<', $endTime);
        }

        // 订单总数
        $totalCount = (clone $query)->count();

        // 成功订单数和金额
        $successQuery = (clone $query)->where('trade_state', Order::TRADE_STATE_SUCCESS);
        $successCount = $successQuery->count();
        $successAmount = $successQuery->sum('total_amount') ?? 0;

        // 商户实收金额
        $receiptAmount = (clone $query)->where('trade_state', Order::TRADE_STATE_SUCCESS)->sum('receipt_amount') ?? 0;

        return [
            'total_count'    => $totalCount,
            'success_count'  => $successCount,
            'success_amount' => bcadd((string)$successAmount, '0', 2),
            'receipt_amount' => bcadd((string)$receiptAmount, '0', 2),
        ];
    }
}
