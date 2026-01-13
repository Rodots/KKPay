<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Order;
use Carbon\Carbon;
use Core\baseController\AdminBase;
use support\Db;
use support\Response;

class IndexController extends AdminBase
{
    /**
     * 仪表盘首页
     */
    public function index(): Response
    {
        $today      = Carbon::today();
        $todayStart = $today->format('Y-m-d 00:00:00');
        $todayEnd   = $today->format('Y-m-d 23:59:59');
        $weekStart  = $today->copy()->subDays(6)->format('Y-m-d 00:00:00');

        // 商户钱包汇总
        $walletStats = Db::table('merchant_wallet')->selectRaw('SUM(available_balance) as available_balance_sum, SUM(unavailable_balance) as unavailable_balance_sum, SUM(margin) as margin_sum, SUM(prepaid) as prepaid_sum')->first();

        // 今日订单统计
        $todayOrderStats = Db::table('order')->selectRaw('COUNT(*) as total_count, SUM(CASE WHEN trade_state = ? THEN 1 ELSE 0 END) as success_count, SUM(CASE WHEN trade_state = ? THEN profit_amount ELSE 0 END) as profit_sum', [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_SUCCESS])->whereBetween('create_time', [$todayStart, $todayEnd])->first();

        $todaySuccessRate = $todayOrderStats->total_count > 0 ? round($todayOrderStats->success_count / $todayOrderStats->total_count * 100, 2) : 0;

        $data = [
            'merchant_count'            => Db::table('merchant')->whereNull('deleted_at')->count(),
            'available_balance_sum'     => $walletStats->available_balance_sum ?? '0.00',
            'unavailable_balance_sum'   => $walletStats->unavailable_balance_sum ?? '0.00',
            'margin_sum'                => $walletStats->margin_sum ?? '0.00',
            'prepaid_sum'               => $walletStats->prepaid_sum ?? '0.00',
            'withdrawal_completed_sum'  => Db::table('merchant_withdrawal_record')->where('status', 'COMPLETED')->sum('received_amount'),
            'order_count'               => Db::table('order')->count(),
            'today_order_count'         => $todayOrderStats->total_count ?? 0,
            'today_success_order_count' => $todayOrderStats->success_count ?? 0,
            'today_success_rate'        => $todaySuccessRate,
            'today_profit_sum'          => $todayOrderStats->profit_sum ?? '0.00',
            'today_risk_count'          => Db::table('risk_log')->whereBetween('created_at', [$todayStart, $todayEnd])->count(),
        ];

        $data['charts'] = [
            'weekly_transaction' => $this->getWeeklyTransactionChart($weekStart, $todayEnd),
            'weekly_order'       => $this->getWeeklyOrderChart($weekStart, $todayEnd),
        ];

        return $this->success('获取成功', $data);
    }

    /**
     * 获取近七日交易额图表数据（按支付方式+汇总）包含退款金额和利润
     */
    private function getWeeklyTransactionChart(string $startDate, string $endDate): array
    {
        $today = Carbon::today();
        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $dates[] = $today->copy()->subDays($i)->format('Y-m-d');
        }

        // 初始化数据结构
        $aggregatedData = [];
        foreach ($dates as $date) {
            foreach (array_keys(Order::PAYMENT_TYPE_MAP) as $type) {
                $aggregatedData[$date . '_' . $type] = ['total_amount' => '0.00', 'profit_amount' => '0.00', 'refund_amount' => '0.00'];
            }
        }

        // 统计成功订单的交易额和利润
        $orders = Db::table('order')->select(['create_time', 'payment_type', 'total_amount', 'profit_amount'])->where('trade_state', Order::TRADE_STATE_SUCCESS)->whereBetween('create_time', [$startDate, $endDate])->get();
        foreach ($orders as $order) {
            $key = Carbon::parse($order->create_time)->format('Y-m-d') . '_' . $order->payment_type;
            if (isset($aggregatedData[$key])) {
                $aggregatedData[$key]['total_amount']  = bcadd($aggregatedData[$key]['total_amount'], (string)$order->total_amount, 2);
                $aggregatedData[$key]['profit_amount'] = bcadd($aggregatedData[$key]['profit_amount'], (string)($order->profit_amount ?? 0), 2);
            }
        }

        // 统计退款金额
        $refunds = Db::table('order_refund')->join('order', 'order_refund.trade_no', '=', 'order.trade_no')->select(['order_refund.created_at', 'order.payment_type', 'order_refund.amount'])->whereBetween('order_refund.created_at', [$startDate, $endDate])->get();
        foreach ($refunds as $refund) {
            $key = Carbon::parse($refund->created_at)->format('Y-m-d') . '_' . $refund->payment_type;
            if (isset($aggregatedData[$key])) {
                $aggregatedData[$key]['refund_amount'] = bcadd($aggregatedData[$key]['refund_amount'], (string)$refund->amount, 2);
            }
        }

        // 统计每个支付方式是否有数据
        $paymentTypeHasData = [];
        foreach (array_keys(Order::PAYMENT_TYPE_MAP) as $type) {
            $paymentTypeHasData[$type] = false;
        }
        foreach ($aggregatedData as $key => $data) {
            $type = substr($key, 11); // 日期长度为10，加上下划线共11
            if ($data['total_amount'] !== '0.00' || $data['profit_amount'] !== '0.00' || $data['refund_amount'] !== '0.00') {
                $paymentTypeHasData[$type] = true;
            }
        }

        // 组装输出数据，过滤无数据的支付方式
        $values = [];
        foreach ($dates as $date) {
            foreach (Order::PAYMENT_TYPE_MAP as $type => $typeName) {
                if (!$paymentTypeHasData[$type]) continue;
                $data     = $aggregatedData[$date . '_' . $type];
                $values[] = [$date, $typeName, $data['total_amount'], $data['refund_amount'], $data['profit_amount']];
            }
        }

        return ['type' => 'bar', 'data' => ['fields' => ['日期', '支付方式', '交易额', '退款金额', '利润'], 'values' => $values]];
    }

    /**
     * 获取近七日订单数图表数据（按支付方式统计，总订单数+交易成功）
     */
    private function getWeeklyOrderChart(string $startDate, string $endDate): array
    {
        $today = Carbon::today();
        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $dates[] = $today->copy()->subDays($i)->format('Y-m-d');
        }

        // 初始化数据结构
        $aggregatedData = [];
        foreach ($dates as $date) {
            foreach (array_keys(Order::PAYMENT_TYPE_MAP) as $type) {
                $aggregatedData[$date . '_' . $type] = ['total_count' => 0, 'success_count' => 0];
            }
        }

        // 统计订单数据
        $orders = Db::table('order')->select(['create_time', 'payment_type', 'trade_state'])->whereBetween('create_time', [$startDate, $endDate])->get();
        foreach ($orders as $order) {
            $key = Carbon::parse($order->create_time)->format('Y-m-d') . '_' . $order->payment_type;
            if (isset($aggregatedData[$key])) {
                $aggregatedData[$key]['total_count']++;
                if ($order->trade_state === Order::TRADE_STATE_SUCCESS) $aggregatedData[$key]['success_count']++;
            }
        }

        // 统计每个支付方式是否有数据
        $paymentTypeHasData = [];
        foreach (array_keys(Order::PAYMENT_TYPE_MAP) as $type) {
            $paymentTypeHasData[$type] = false;
        }
        foreach ($aggregatedData as $key => $data) {
            $type = substr($key, 11);
            if ($data['total_count'] > 0) $paymentTypeHasData[$type] = true;
        }

        // 组装输出数据，过滤无数据的支付方式
        $values = [];
        foreach ($dates as $date) {
            foreach (Order::PAYMENT_TYPE_MAP as $type => $typeName) {
                if (!$paymentTypeHasData[$type]) continue;
                $data     = $aggregatedData[$date . '_' . $type];
                $values[] = [$date, $typeName, $data['total_count'], $data['success_count']];
            }
        }

        return ['type' => 'bar', 'data' => ['fields' => ['日期', '支付方式', '总订单数', '交易成功数'], 'values' => $values]];
    }
}
