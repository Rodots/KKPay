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
     *
     * @return Response
     */
    public function index(): Response
    {
        // 获取今日日期范围
        $todayStart = Carbon::today()->format('Y-m-d 00:00:00');
        $todayEnd   = Carbon::today()->format('Y-m-d 23:59:59');

        // 获取近七日日期范围
        $weekStart = Carbon::today()->subDays(6)->format('Y-m-d 00:00:00');
        $weekEnd   = $todayEnd;

        // 商户钱包汇总（单条SQL查询）
        $walletStats = Db::table('merchant_wallet')
            ->selectRaw('SUM(available_balance) as available_balance_sum,SUM(unavailable_balance) as unavailable_balance_sum,SUM(margin) as margin_sum,SUM(prepaid) as prepaid_sum')
            ->first();

        // 今日订单统计（单条SQL查询）
        $todayOrderStats = Db::table('order')
            ->selectRaw(' COUNT(*) as total_count, SUM(CASE WHEN trade_state = ? THEN 1 ELSE 0 END) as success_count, SUM(CASE WHEN trade_state = ? THEN profit_amount ELSE 0 END) as profit_sum', [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_SUCCESS])
            ->whereBetween('create_time', [$todayStart, $todayEnd])
            ->first();

        // 计算今日订单成功率
        $todaySuccessRate = $todayOrderStats->total_count > 0
            ? round($todayOrderStats->success_count / $todayOrderStats->total_count * 100, 2)
            : 0;

        // 基础统计数据
        $data = [
            // 1. 平台商户总数
            'merchant_count'            => Db::table('merchant')->whereNull('deleted_at')->count(),
            // 2. 商户可用余额总和
            'available_balance_sum'     => $walletStats->available_balance_sum ?? '0.00',
            // 3. 商户不可用余额总和
            'unavailable_balance_sum'   => $walletStats->unavailable_balance_sum ?? '0.00',
            // 4. 商户保证金总和
            'margin_sum'                => $walletStats->margin_sum ?? '0.00',
            // 5. 平台预付商户总金额
            'prepaid_sum'               => $walletStats->prepaid_sum ?? '0.00',
            // 6. 平台已提款总金额（仅统计提款成功）
            'withdrawal_completed_sum'  => Db::table('merchant_withdrawal_record')->where('status', 'COMPLETED')->sum('received_amount'),
            // 7. 平台订单总数
            'order_count'               => Db::table('order')->count(),
            // 8. 今日订单总数
            'today_order_count'         => $todayOrderStats->total_count ?? 0,
            // 9. 今日交易成功订单
            'today_success_order_count' => $todayOrderStats->success_count ?? 0,
            // 10. 今日订单支付成功率
            'today_success_rate'        => $todaySuccessRate,
            // 11. 今日赚取订单服务费总利润
            'today_profit_sum'          => $todayOrderStats->profit_sum ?? '0.00',
            // 12. 今日触发风控次数
            'today_risk_count'          => Db::table('risk_log')->whereBetween('created_at', [$todayStart, $todayEnd])->count(),
        ];

        // 图表数据
        $data['charts'] = [
            // 近七日交易额柱状图
            'weekly_transaction' => $this->getWeeklyTransactionChart($weekStart, $weekEnd),
            // 近七日订单数统计
            'weekly_order'       => $this->getWeeklyOrderChart($weekStart, $weekEnd),
        ];

        return $this->success('获取成功', $data);
    }

    /**
     * 获取近七日交易额图表数据（按支付方式+汇总）包含退款金额和利润
     *
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function getWeeklyTransactionChart(string $startDate, string $endDate): array
    {
        // 支付方式映射
        $paymentTypeMap = [
            Order::PAYMENT_TYPE_ALIPAY    => '支付宝',
            Order::PAYMENT_TYPE_WECHATPAY => '微信支付',
            Order::PAYMENT_TYPE_BANK      => '银联/银行卡',
            Order::PAYMENT_TYPE_UNIONPAY  => '云闪付',
            Order::PAYMENT_TYPE_QQWALLET  => 'QQ钱包',
            Order::PAYMENT_TYPE_JDPAY     => '京东支付',
            Order::PAYMENT_TYPE_PAYPAL    => 'PayPal',
        ];

        // 生成近七日日期列表
        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $dates[] = Carbon::today()->subDays($i)->format('Y-m-d');
        }

        // 初始化数据结构
        $aggregatedData = [];
        foreach ($dates as $date) {
            foreach (array_keys($paymentTypeMap) as $type) {
                $key = $date . '_' . $type;
                $aggregatedData[$key] = [
                    'total_amount'  => '0.00',
                    'profit_amount' => '0.00',
                    'refund_amount' => '0.00',
                ];
            }
        }

        // 查询成功订单的交易额和利润
        $orders = Db::table('order')
            ->select(['create_time', 'payment_type', 'total_amount', 'profit_amount'])
            ->where('trade_state', Order::TRADE_STATE_SUCCESS)
            ->whereBetween('create_time', [$startDate, $endDate])
            ->get();

        foreach ($orders as $order) {
            $date = Carbon::parse($order->create_time)->format('Y-m-d');
            $key  = $date . '_' . $order->payment_type;
            if (isset($aggregatedData[$key])) {
                $aggregatedData[$key]['total_amount']  = bcadd($aggregatedData[$key]['total_amount'], (string)$order->total_amount, 2);
                $aggregatedData[$key]['profit_amount'] = bcadd($aggregatedData[$key]['profit_amount'], (string)($order->profit_amount ?? 0), 2);
            }
        }

        // 查询退款金额（关联订单表获取支付方式）
        $refunds = Db::table('order_refund')
            ->join('order', 'order_refund.trade_no', '=', 'order.trade_no')
            ->select(['order_refund.created_at', 'order.payment_type', 'order_refund.amount'])
            ->whereBetween('order_refund.created_at', [$startDate, $endDate])
            ->get();

        foreach ($refunds as $refund) {
            $date = Carbon::parse($refund->created_at)->format('Y-m-d');
            $key  = $date . '_' . $refund->payment_type;
            if (isset($aggregatedData[$key])) {
                $aggregatedData[$key]['refund_amount'] = bcadd($aggregatedData[$key]['refund_amount'], (string)$refund->amount, 2);
            }
        }

        // 组装输出数据
        $values = [];
        foreach ($dates as $date) {
            foreach ($paymentTypeMap as $type => $typeName) {
                $key  = $date . '_' . $type;
                $data = $aggregatedData[$key];
                $values[] = [
                    $date,
                    $typeName,
                    $data['total_amount'],
                    $data['refund_amount'],
                    $data['profit_amount'],
                ];
            }
        }

        return [
            'type' => 'bar',
            'data' => [
                'fields' => ['日期', '支付方式', '交易额', '退款金额', '利润'],
                'values' => $values,
            ],
        ];
    }

    /**
     * 获取近七日订单数图表数据（按支付方式统计，总订单数+交易成功）
     *
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function getWeeklyOrderChart(string $startDate, string $endDate): array
    {
        // 支付方式映射
        $paymentTypeMap = [
            Order::PAYMENT_TYPE_ALIPAY    => '支付宝',
            Order::PAYMENT_TYPE_WECHATPAY => '微信支付',
            Order::PAYMENT_TYPE_BANK      => '银联/银行卡',
            Order::PAYMENT_TYPE_UNIONPAY  => '云闪付',
            Order::PAYMENT_TYPE_QQWALLET  => 'QQ钱包',
            Order::PAYMENT_TYPE_JDPAY     => '京东支付',
            Order::PAYMENT_TYPE_PAYPAL    => 'PayPal',
        ];

        // 生成近七日日期列表
        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $dates[] = Carbon::today()->subDays($i)->format('Y-m-d');
        }

        // 初始化数据结构
        $aggregatedData = [];
        foreach ($dates as $date) {
            foreach (array_keys($paymentTypeMap) as $type) {
                $key = $date . '_' . $type;
                $aggregatedData[$key] = [
                    'total_count'   => 0,
                    'success_count' => 0,
                ];
            }
        }

        // 查询订单数据
        $orders = Db::table('order')
            ->select(['create_time', 'payment_type', 'trade_state'])
            ->whereBetween('create_time', [$startDate, $endDate])
            ->get();

        foreach ($orders as $order) {
            $date = Carbon::parse($order->create_time)->format('Y-m-d');
            $key  = $date . '_' . $order->payment_type;
            if (isset($aggregatedData[$key])) {
                $aggregatedData[$key]['total_count']++;
                if ($order->trade_state === Order::TRADE_STATE_SUCCESS) {
                    $aggregatedData[$key]['success_count']++;
                }
            }
        }

        // 组装输出数据
        $values = [];
        foreach ($dates as $date) {
            foreach ($paymentTypeMap as $type => $typeName) {
                $key  = $date . '_' . $type;
                $data = $aggregatedData[$key];
                $values[] = [
                    $date,
                    $typeName,
                    $data['total_count'],
                    $data['success_count'],
                ];
            }
        }

        return [
            'type' => 'bar',
            'data' => [
                'fields' => ['日期', '支付方式', '总订单数', '交易成功数'],
                'values' => $values,
            ],
        ];
    }
}
