<?php

declare(strict_types=1);

namespace app\merchant\controller;

use app\model\Order;
use Carbon\Carbon;
use Core\baseController\MerchantBase;
use support\Db;
use support\Request;
use support\Response;

/**
 * 商户端 - 首页仪表盘控制器
 */
class IndexController extends MerchantBase
{
    /**
     * 商户端仪表盘首页
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $merchantId = $request->MerchantInfo['id'];

        $today      = Carbon::today();
        $todayStart = $today->format('Y-m-d 00:00:00');
        $todayEnd   = $today->format('Y-m-d 23:59:59');

        $yesterday      = Carbon::yesterday();
        $yesterdayStart = $yesterday->format('Y-m-d 00:00:00');
        $yesterdayEnd   = $yesterday->format('Y-m-d 23:59:59');

        // 当前商户钱包
        $walletStats = Db::table('merchant_wallet')->where('merchant_id', $merchantId)->selectRaw('available_balance, unavailable_balance, margin, prepaid')->first();

        // 今日订单统计（当前商户）
        $todayOrderStats = Db::table('order')->where('merchant_id', $merchantId)->selectRaw('COUNT(*) as total_count, SUM(total_amount) as total_amount_sum, SUM(CASE WHEN trade_state = ? THEN 1 ELSE 0 END) as success_count, SUM(CASE WHEN trade_state = ? THEN total_amount ELSE 0 END) as success_amount_sum, SUM(CASE WHEN trade_state = ? THEN receipt_amount ELSE 0 END) as success_receipt_sum', [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_SUCCESS])->whereBetween('create_time', [$todayStart, $todayEnd])->first();

        // 昨日订单统计（当前商户）
        $yesterdayOrderStats = Db::table('order')->where('merchant_id', $merchantId)->selectRaw('COUNT(*) as total_count, SUM(total_amount) as total_amount_sum, SUM(CASE WHEN trade_state = ? THEN 1 ELSE 0 END) as success_count, SUM(CASE WHEN trade_state = ? THEN total_amount ELSE 0 END) as success_amount_sum, SUM(CASE WHEN trade_state = ? THEN receipt_amount ELSE 0 END) as success_receipt_sum', [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_SUCCESS])->whereBetween('create_time', [$yesterdayStart, $yesterdayEnd])->first();

        $todaySuccessRate     = $todayOrderStats->total_count > 0 ? round($todayOrderStats->success_count / $todayOrderStats->total_count * 100, 2) : 0;
        $yesterdaySuccessRate = $yesterdayOrderStats->total_count > 0 ? round($yesterdayOrderStats->success_count / $yesterdayOrderStats->total_count * 100, 2) : 0;

        $data = [
            'available_balance'             => $walletStats->available_balance ?? '0.00',
            'unavailable_balance'           => $walletStats->unavailable_balance ?? '0.00',
            'margin'                        => $walletStats->margin ?? '0.00',
            'prepaid'                       => $walletStats->prepaid ?? '0.00',
            'order_count'                   => Db::table('order')->where('merchant_id', $merchantId)->count(),
            'today_order_count'             => $todayOrderStats->total_count ?? 0,
            'today_total_amount_sum'        => $todayOrderStats->total_amount_sum ?? '0.00',
            'today_success_order_count'     => $todayOrderStats->success_count ?? 0,
            'today_success_rate'            => $todaySuccessRate,
            'today_success_amount_sum'      => $todayOrderStats->success_amount_sum ?? '0.00',
            'today_success_receipt_sum'     => $todayOrderStats->success_receipt_sum ?? '0.00',
            'yesterday_order_count'         => $yesterdayOrderStats->total_count ?? 0,
            'yesterday_total_amount_sum'    => $yesterdayOrderStats->total_amount_sum ?? '0.00',
            'yesterday_success_order_count' => $yesterdayOrderStats->success_count ?? 0,
            'yesterday_success_rate'        => $yesterdaySuccessRate,
            'yesterday_success_amount_sum'  => $yesterdayOrderStats->success_amount_sum ?? '0.00',
            'yesterday_success_receipt_sum' => $yesterdayOrderStats->success_receipt_sum ?? '0.00',
        ];

        return $this->success('获取成功', $data);
    }
}
