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
        $walletStats = Db::table('merchant_wallet')
            ->selectRaw('SUM(available_balance) as available_balance_sum, SUM(unavailable_balance) as unavailable_balance_sum, SUM(margin) as margin_sum, SUM(prepaid) as prepaid_sum')
            ->first();

        // 今日订单统计
        $todayOrderStats = Db::table('order')
            ->selectRaw('COUNT(*) as total_count, SUM(CASE WHEN trade_state = ? THEN 1 ELSE 0 END) as success_count, SUM(CASE WHEN trade_state = ? THEN profit_amount ELSE 0 END) as profit_sum', [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_SUCCESS])
            ->whereBetween('create_time', [$todayStart, $todayEnd])
            ->first();

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
            'charts'                    => [
                'weekly_transaction' => $this->getWeeklyTransactionChart($weekStart, $todayEnd),
                'weekly_order'       => $this->getWeeklyOrderChart($weekStart, $todayEnd),
            ],
        ];

        return $this->success('获取成功', $data);
    }

    /**
     * 获取近七日交易额图表数据（ECharts Dataset 格式）
     */
    private function getWeeklyTransactionChart(string $startDate, string $endDate): array
    {
        $dates = $this->getLast7Dates();
        $data  = $this->initChartData($dates, ['total_amount', 'refund_amount', 'profit_amount'], 0.0);

        // 统计成功订单
        Db::table('order')
            ->select(['create_time', 'payment_type', 'total_amount', 'profit_amount'])
            ->where('trade_state', Order::TRADE_STATE_SUCCESS)
            ->whereBetween('create_time', [$startDate, $endDate])
            ->get()
            ->each(function ($order) use (&$data) {
                $date = Carbon::parse($order->create_time)->format('Y-m-d');
                $type = $order->payment_type;
                if (isset($data[$type]['total_amount'][$date])) {
                    $data[$type]['total_amount'][$date]  += (float)$order->total_amount;
                    $data[$type]['profit_amount'][$date] += (float)($order->profit_amount ?? 0);
                }
            });

        // 统计退款
        Db::table('order_refund')
            ->join('order', 'order_refund.trade_no', '=', 'order.trade_no')
            ->select(['order_refund.created_at', 'order.payment_type', 'order_refund.amount'])
            ->whereBetween('order_refund.created_at', [$startDate, $endDate])
            ->get()
            ->each(function ($refund) use (&$data) {
                $date = Carbon::parse($refund->created_at)->format('Y-m-d');
                $type = $refund->payment_type;
                if (isset($data[$type]['refund_amount'][$date])) {
                    $data[$type]['refund_amount'][$date] += (float)$refund->amount;
                }
            });

        return $this->formatToEchartsDataset($data, ['total_amount' => '交易额', 'refund_amount' => '退款金额', 'profit_amount' => '利润'], $dates, true);
    }

    /**
     * 获取近七日订单数图表数据（ECharts Dataset 格式）
     */
    private function getWeeklyOrderChart(string $startDate, string $endDate): array
    {
        $dates = $this->getLast7Dates();
        $data  = $this->initChartData($dates, ['total_count', 'success_count'], 0);

        Db::table('order')
            ->select(['create_time', 'payment_type', 'trade_state'])
            ->whereBetween('create_time', [$startDate, $endDate])
            ->get()
            ->each(function ($order) use (&$data) {
                $date = Carbon::parse($order->create_time)->format('Y-m-d');
                $type = $order->payment_type;
                if (isset($data[$type]['total_count'][$date])) {
                    $data[$type]['total_count'][$date]++;
                    if ($order->trade_state === Order::TRADE_STATE_SUCCESS) {
                        $data[$type]['success_count'][$date]++;
                    }
                }
            });

        return $this->formatToEchartsDataset($data, ['total_count' => '总订单数', 'success_count' => '交易成功数'], $dates, false);
    }

    /**
     * 生成近7天日期数组
     */
    private function getLast7Dates(): array
    {
        $today = Carbon::today();
        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $dates[] = $today->copy()->subDays($i)->format('Y-m-d');
        }
        return $dates;
    }

    /**
     * 初始化图表数据结构
     */
    private function initChartData(array $dates, array $metrics, $default): array
    {
        $data = [];
        foreach (Order::PAYMENT_TYPE_MAP as $type => $_) {
            foreach ($metrics as $metric) {
                $data[$type][$metric] = array_fill_keys($dates, $default);
            }
        }
        return $data;
    }

    /**
     * 格式化为 ECharts Dataset 二维数组规范
     */
    private function formatToEchartsDataset(array $data, array $metricNames, array $dates, bool $isFloat = false): array
    {
        // 首行：表头
        $result = [array_merge(['指标'], $dates)];

        foreach (Order::PAYMENT_TYPE_MAP as $type => $typeName) {
            foreach ($metricNames as $metricKey => $metricLabel) {
                $values = array_values($data[$type][$metricKey]);

                // 过滤全为 0 的系列，避免前端渲染空白图例
                if (array_sum($values) > 0) {
                    $formatted = $isFloat ? array_map(fn($v) => round((float)$v, 2), $values) : $values;
                    $result[]  = array_merge(["{$typeName}-{$metricLabel}"], $formatted);
                }
            }
        }

        return $result;
    }
}
