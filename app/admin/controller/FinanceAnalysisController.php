<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Merchant;
use app\model\Order;
use app\model\PaymentChannel;
use app\model\PaymentChannelAccount;
use Carbon\Carbon;
use Core\baseController\AdminBase;
use Illuminate\Support\Collection;
use support\Request;
use support\Response;
use Throwable;

/**
 * 财务分析控制器
 *
 * 提供支付通道分析、支付通道子账户分析、商户分析三个列表，
 * 从 kkpay_order 表按不同维度聚合订单统计，含今日与昨日数据。
 */
class FinanceAnalysisController extends AdminBase
{
    /**
     * 支付通道分析 -- 按 payment_channel 维度聚合订单统计
     *
     * @param Request $request
     * @return Response
     */
    public function paymentChannel(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 20);
        $params = $request->only(['code', 'name', 'payment_type', 'status', 'create_time']);

        try {
            validate([
                'code'         => ['max:16', 'regex' => '/^[A-Z0-9]+$/'],
                'name'         => ['max:64'],
                'payment_type' => ['max:32'],
                'status'       => ['in:0,1'],
                'create_time'  => ['array'],
            ], [
                'code.max'          => '通道编码不能超过16个字符',
                'code.regex'        => '通道编码只能包含大写字母和数字',
                'name.max'          => '通道名称不能超过64个字符',
                'payment_type.max'  => '支付方式格式不正确',
                'status.in'         => '状态值不正确',
                'create_time.array' => '时间范围格式不正确',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $hasDateFilter = $this->parseCreateTime($params);

        $query = PaymentChannel::select(['id', 'code', 'name', 'payment_type', 'gateway', 'cost', 'status'])
            ->when($params, function ($q) use ($params) {
                foreach ($params as $key => $value) {
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $value = is_string($value) ? trim($value) : $value;
                    match ($key) {
                        'code' => $q->where('code', $value),
                        'name' => $q->where('name', 'like', "%$value%"),
                        'payment_type' => $q->where('payment_type', $value),
                        'status' => $q->where('status', (bool)$value),
                        default => null,
                    };
                }
                return $q;
            });

        $total = $query->count();
        $list  = $query->offset($from)->limit($limit)->orderByDesc('id')->get()->append(['payment_type_text']);

        if ($list->isEmpty()) {
            return $this->success(data: ['list' => $list, 'total' => $total]);
        }

        // 查询当前页通道的所有子账户，建立 account_id -> channel_id 映射
        $channel_ids       = $list->pluck('id')->toArray();
        $accountChannelMap = PaymentChannelAccount::whereIn('payment_channel_id', $channel_ids)
            ->pluck('payment_channel_id', 'id')
            ->toArray();
        $account_ids       = array_keys($accountChannelMap);

        // 按 account_id 聚合汇总统计
        $accountStats = $this->getOrderStatsByAccountIds($account_ids, $params['create_time'] ?? []);

        // PHP 层按 channel_id 合并统计（一个通道可能有多个子账户）
        $channelStats = $this->mergeAccountStatsByChannel(
            $accountStats,
            $accountChannelMap,
            ['total_count', 'success_count'],
            ['total_amount', 'receipt_amount', 'fee_amount', 'profit_amount']
        );

        // 合并统计到列表项
        $list->each(function ($item) use ($channelStats, $hasDateFilter) {
            $s = $channelStats[$item->id] ?? null;
            $item->setAttribute('total_count', $s['total_count'] ?? 0);
            $item->setAttribute('success_count', $s['success_count'] ?? 0);
            $item->setAttribute('total_amount', $s['total_amount'] ?? '0.00');
            $item->setAttribute('receipt_amount', $s['receipt_amount'] ?? '0.00');
            $item->setAttribute('fee_amount', $s['fee_amount'] ?? '0.00');
            $item->setAttribute('profit_amount', $s['profit_amount'] ?? '0.00');
            $item->setAttribute('success_rate', ($s['total_count'] ?? 0) > 0
                ? bcdiv((string)($s['success_count'] ?? 0), (string)($s['total_count'] ?? 0), 4) : '0.0000');

            // 传入日期筛选时，今日/昨日统一输出0
            $this->appendDailyAttrs($item, $hasDateFilter ? null : ($channelStats[$item->id] ?? null));
        });

        return $this->success(data: ['list' => $list, 'total' => $total]);
    }

    /**
     * 支付通道子账户分析 -- 按 payment_channel_account 维度聚合订单统计
     *
     * @param Request $request
     * @return Response
     */
    public function channelAccount(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 20);
        $params = $request->only(['name', 'channel_code', 'status', 'maintenance', 'create_time']);

        try {
            validate([
                'name'         => ['max:64'],
                'channel_code' => ['max:16', 'regex' => '/^[A-Z0-9]+$/'],
                'status'       => ['in:0,1'],
                'maintenance'  => ['in:0,1'],
                'create_time'  => ['array'],
            ], [
                'name.max'           => '子账户名称不能超过64个字符',
                'channel_code.max'   => '通道编码不能超过16个字符',
                'channel_code.regex' => '通道编码只能包含大写字母和数字',
                'status.in'          => '状态值不正确',
                'maintenance.in'     => '维护状态值不正确',
                'create_time.array'  => '时间范围格式不正确',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $hasDateFilter = $this->parseCreateTime($params);

        // 将通道编码转为通道ID用于筛选
        $channelId = null;
        if (!empty($params['channel_code'])) {
            $channelId = PaymentChannel::where('code', $params['channel_code'])->value('id');
            if ($channelId === null) {
                return $this->success(data: ['list' => [], 'total' => 0]);
            }
        }

        $query = PaymentChannelAccount::with(['paymentChannel:id,code,name'])
            ->select(['id', 'name', 'payment_channel_id', 'status', 'maintenance'])
            ->when($channelId, fn($q) => $q->where('payment_channel_id', $channelId))
            ->when($params, function ($q) use ($params) {
                foreach ($params as $key => $value) {
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $value = is_string($value) ? trim($value) : $value;
                    match ($key) {
                        'name' => $q->where('name', 'like', "%$value%"),
                        'status' => $q->where('status', (bool)$value),
                        'maintenance' => $q->where('maintenance', (bool)$value),
                        default => null,
                    };
                }
                return $q;
            });

        $total = $query->count();
        $list  = $query->offset($from)->limit($limit)->orderByDesc('id')->get();

        if ($list->isEmpty()) {
            return $this->success(data: ['list' => $list, 'total' => $total]);
        }

        $account_ids = $list->pluck('id')->toArray();

        $stats      = $this->getOrderStatsByAccountIds($account_ids, $params['create_time'] ?? []);
        $dailyStats = $hasDateFilter ? collect() : $this->getDailyOrderStatsByAccountIds($account_ids);

        $this->appendStatsToItems($list, $stats);
        $this->appendDailyStatsToItems($list, $dailyStats, $hasDateFilter);

        return $this->success(data: ['list' => $list, 'total' => $total]);
    }

    /**
     * 商户分析 -- 按 merchant 维度聚合订单统计
     *
     * @param Request $request
     * @return Response
     */
    public function merchant(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 20);
        $params = $request->only(['merchant_number', 'nickname', 'create_time']);

        try {
            validate([
                'merchant_number' => ['alphaNum', 'startWith:M', 'length:16'],
                'nickname'        => ['max:64'],
                'create_time'     => ['array'],
            ], [
                'merchant_number.alphaNum'  => '商户编号格式不正确',
                'merchant_number.startWith' => '商户编号格式不正确',
                'merchant_number.length'    => '商户编号格式不正确',
                'nickname.max'              => '商户昵称不能超过64个字符',
                'create_time.array'         => '时间范围格式不正确',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $hasDateFilter = $this->parseCreateTime($params);

        $query = Merchant::select(['id', 'merchant_number', 'nickname'])
            ->when($params, function ($q) use ($params) {
                foreach ($params as $key => $value) {
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $value = is_string($value) ? trim($value) : $value;
                    match ($key) {
                        'merchant_number' => $q->where('merchant_number', $value),
                        'nickname' => $q->where('nickname', 'like', "%$value%"),
                        default => null,
                    };
                }
                return $q;
            });

        $total = $query->count();
        $list  = $query->offset($from)->limit($limit)->orderByDesc('id')->get();

        if ($list->isEmpty()) {
            return $this->success(data: ['list' => $list, 'total' => $total]);
        }

        $merchant_ids = $list->pluck('id')->toArray();

        $stats      = $this->getOrderStatsByMerchantIds($merchant_ids, $params['create_time'] ?? []);
        $dailyStats = $hasDateFilter ? collect() : $this->getDailyOrderStatsByMerchantIds($merchant_ids);

        $this->appendStatsToItems($list, $stats);
        $this->appendDailyStatsToItems($list, $dailyStats, $hasDateFilter);

        return $this->success(data: ['list' => $list, 'total' => $total]);
    }

    /**
     * 解析 create_time 参数，将日期字符串补全为完整时间范围
     *
     * 入参格式：["2026-01-01", "2026-05-15"]（仅日期，不含时分秒）
     * 转换后：["2026-01-01 00:00:00", "2026-05-15 23:59:59"]
     *
     * @param array $params 请求参数（引用修改）
     * @return bool          是否存在有效的日期筛选
     */
    private function parseCreateTime(array &$params): bool
    {
        if (empty($params['create_time']) || !is_array($params['create_time']) || count($params['create_time']) < 2) {
            unset($params['create_time']);
            return false;
        }

        $start = trim($params['create_time'][0]);
        $end   = trim($params['create_time'][1]);

        if ($start === '' || $end === '') {
            unset($params['create_time']);
            return false;
        }

        $params['create_time'] = [
            $start . ' 00:00:00',
            $end . ' 23:59:59',
        ];

        return true;
    }

    /**
     * 按 payment_channel_account_id 聚合订单统计
     *
     * @param array $account_ids 子账户ID列表
     * @param array $create_time 订单创建时间范围 [start, end]
     * @return Collection         按 payment_channel_account_id 为键的统计集合
     */
    private function getOrderStatsByAccountIds(array $account_ids, array $create_time = []): Collection
    {
        if (empty($account_ids)) {
            return collect();
        }

        $paidStates = $this->getPaidStatesCondition();

        $query = Order::select(['payment_channel_account_id'])
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN 1 ELSE 0 END) as success_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN total_amount ELSE 0 END) as total_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN receipt_amount ELSE 0 END) as receipt_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN fee_amount ELSE 0 END) as fee_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN profit_amount ELSE 0 END) as profit_amount")
            ->whereIn('payment_channel_account_id', $account_ids);

        if (!empty($create_time)) {
            $query->whereBetween('create_time', [$create_time[0], $create_time[1]]);
        }

        return $query->groupBy('payment_channel_account_id')->get()->keyBy('payment_channel_account_id');
    }

    /**
     * 按 merchant_id 聚合订单统计
     *
     * @param array $merchant_ids 商户ID列表
     * @param array $create_time  订单创建时间范围 [start, end]
     * @return Collection         按 merchant_id 为键的统计集合
     */
    private function getOrderStatsByMerchantIds(array $merchant_ids, array $create_time = []): Collection
    {
        if (empty($merchant_ids)) {
            return collect();
        }

        $paidStates = $this->getPaidStatesCondition();

        $query = Order::select(['merchant_id'])
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN 1 ELSE 0 END) as success_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN total_amount ELSE 0 END) as total_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN receipt_amount ELSE 0 END) as receipt_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN fee_amount ELSE 0 END) as fee_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) THEN profit_amount ELSE 0 END) as profit_amount")
            ->whereIn('merchant_id', $merchant_ids);

        if (!empty($create_time)) {
            $query->whereBetween('create_time', [$create_time[0], $create_time[1]]);
        }

        return $query->groupBy('merchant_id')->get()->keyBy('merchant_id');
    }

    /**
     * 按 payment_channel_account_id 聚合今日与昨日订单统计
     *
     * @param array $account_ids 子账户ID列表
     * @return Collection         按 payment_channel_account_id 为键的统计集合
     */
    private function getDailyOrderStatsByAccountIds(array $account_ids): Collection
    {
        if (empty($account_ids)) {
            return collect();
        }

        [$todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd] = $this->getDailyTimeRange();
        $paidStates = $this->getPaidStatesCondition();

        return Order::select(['payment_channel_account_id'])
            ->selectRaw("SUM(CASE WHEN create_time BETWEEN '$todayStart' AND '$todayEnd' THEN 1 ELSE 0 END) as today_total_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$todayStart' AND '$todayEnd' THEN 1 ELSE 0 END) as today_success_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$todayStart' AND '$todayEnd' THEN total_amount ELSE 0 END) as today_total_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$todayStart' AND '$todayEnd' THEN fee_amount ELSE 0 END) as today_fee_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$todayStart' AND '$todayEnd' THEN profit_amount ELSE 0 END) as today_profit_amount")
            ->selectRaw("SUM(CASE WHEN create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN 1 ELSE 0 END) as yesterday_total_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN 1 ELSE 0 END) as yesterday_success_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN total_amount ELSE 0 END) as yesterday_total_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN fee_amount ELSE 0 END) as yesterday_fee_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN profit_amount ELSE 0 END) as yesterday_profit_amount")
            ->whereIn('payment_channel_account_id', $account_ids)
            ->where(function ($q) use ($todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd) {
                $q->whereBetween('create_time', [$todayStart, $todayEnd])
                    ->orWhereBetween('create_time', [$yesterdayStart, $yesterdayEnd]);
            })
            ->groupBy('payment_channel_account_id')
            ->get()
            ->keyBy('payment_channel_account_id');
    }

    /**
     * 按 merchant_id 聚合今日与昨日订单统计
     *
     * @param array $merchant_ids 商户ID列表
     * @return Collection         按 merchant_id 为键的统计集合
     */
    private function getDailyOrderStatsByMerchantIds(array $merchant_ids): Collection
    {
        if (empty($merchant_ids)) {
            return collect();
        }

        [$todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd] = $this->getDailyTimeRange();
        $paidStates = $this->getPaidStatesCondition();

        return Order::select(['merchant_id'])
            ->selectRaw("SUM(CASE WHEN create_time BETWEEN '$todayStart' AND '$todayEnd' THEN 1 ELSE 0 END) as today_total_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$todayStart' AND '$todayEnd' THEN 1 ELSE 0 END) as today_success_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$todayStart' AND '$todayEnd' THEN total_amount ELSE 0 END) as today_total_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$todayStart' AND '$todayEnd' THEN fee_amount ELSE 0 END) as today_fee_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$todayStart' AND '$todayEnd' THEN profit_amount ELSE 0 END) as today_profit_amount")
            ->selectRaw("SUM(CASE WHEN create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN 1 ELSE 0 END) as yesterday_total_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN 1 ELSE 0 END) as yesterday_success_count")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN total_amount ELSE 0 END) as yesterday_total_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN fee_amount ELSE 0 END) as yesterday_fee_amount")
            ->selectRaw("SUM(CASE WHEN trade_state IN ($paidStates) AND create_time BETWEEN '$yesterdayStart' AND '$yesterdayEnd' THEN profit_amount ELSE 0 END) as yesterday_profit_amount")
            ->whereIn('merchant_id', $merchant_ids)
            ->where(function ($q) use ($todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd) {
                $q->whereBetween('create_time', [$todayStart, $todayEnd])
                    ->orWhereBetween('create_time', [$yesterdayStart, $yesterdayEnd]);
            })
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');
    }

    /**
     * 将按 account_id 分组的统计合并为按 channel_id 分组
     *
     * @param Collection $accountStats      按 payment_channel_account_id 为键的统计集合
     * @param array      $accountChannelMap account_id => channel_id 映射
     * @param array      $intFields         整数字段列表（直接累加）
     * @param array      $decimalFields     小数字段列表（bcadd 累加）
     * @return array     按 channel_id 为键的统计数组
     */
    private function mergeAccountStatsByChannel(Collection $accountStats, array $accountChannelMap, array $intFields, array $decimalFields): array
    {
        $channelStats = [];
        foreach ($accountStats as $accountId => $row) {
            $cid = $accountChannelMap[$accountId] ?? null;
            if ($cid === null) {
                continue;
            }
            if (!isset($channelStats[$cid])) {
                $channelStats[$cid] = array_merge(
                    array_fill_keys($intFields, 0),
                    array_fill_keys($decimalFields, '0.00'),
                );
            }
            foreach ($intFields as $field) {
                $channelStats[$cid][$field] += (int)($row->$field ?? 0);
            }
            foreach ($decimalFields as $field) {
                $channelStats[$cid][$field] = bcadd($channelStats[$cid][$field], $row->$field ?? '0', 2);
            }
        }
        return $channelStats;
    }

    /**
     * 将聚合统计数据附加到列表项
     *
     * @param mixed      $items 列表集合
     * @param Collection $stats 统计集合（已 keyBy）
     */
    private function appendStatsToItems(mixed $items, Collection $stats): void
    {
        $items->each(function ($item) use ($stats) {
            $s = $stats[$item->id] ?? null;
            $item->setAttribute('total_count', (int)($s->total_count ?? 0));
            $item->setAttribute('success_count', (int)($s->success_count ?? 0));
            $item->setAttribute('total_amount', $s->total_amount ?? '0.00');
            $item->setAttribute('receipt_amount', $s->receipt_amount ?? '0.00');
            $item->setAttribute('fee_amount', $s->fee_amount ?? '0.00');
            $item->setAttribute('profit_amount', $s->profit_amount ?? '0.00');
            $item->setAttribute('success_rate', ($s->total_count ?? 0) > 0
                ? bcdiv((string)($s->success_count ?? 0), (string)($s->total_count ?? 0), 4) : '0.0000');
        });
    }

    /**
     * 将今日与昨日统计数据附加到列表项
     *
     * @param mixed      $items         列表集合
     * @param Collection $dailyStats    每日统计集合（已 keyBy），有日期筛选时为空集合
     * @param bool       $hasDateFilter 是否存在日期筛选
     */
    private function appendDailyStatsToItems(mixed $items, Collection $dailyStats, bool $hasDateFilter = false): void
    {
        $items->each(function ($item) use ($dailyStats, $hasDateFilter) {
            // 存在日期筛选时，今日/昨日统一输出0
            $d = $hasDateFilter ? null : ($dailyStats[$item->id] ?? null);
            $this->appendDailyAttrs($item, $d);
        });
    }

    /**
     * 将今日与昨日统计字段附加到单个列表项
     *
     * @param mixed $item 列表项模型
     * @param mixed $d    每日统计数据行（stdClass 或 null），null 时输出0
     */
    private function appendDailyAttrs(mixed $item, mixed $d): void
    {
        $item->setAttribute('today_total_count', $d->today_total_count ?? 0);
        $item->setAttribute('today_success_count', $d->today_success_count ?? 0);
        $item->setAttribute('today_total_amount', $d->today_total_amount ?? '0.00');
        $item->setAttribute('today_fee_amount', $d->today_fee_amount ?? '0.00');
        $item->setAttribute('today_profit_amount', $d->today_profit_amount ?? '0.00');
        $item->setAttribute('today_success_rate', ($d->today_total_count ?? 0) > 0
            ? bcdiv((string)($d->today_success_count ?? 0), (string)($d->today_total_count ?? 0), 4) : '0.0000');

        $item->setAttribute('yesterday_total_count', $d->yesterday_total_count ?? 0);
        $item->setAttribute('yesterday_success_count', $d->yesterday_success_count ?? 0);
        $item->setAttribute('yesterday_total_amount', $d->yesterday_total_amount ?? '0.00');
        $item->setAttribute('yesterday_fee_amount', $d->yesterday_fee_amount ?? '0.00');
        $item->setAttribute('yesterday_profit_amount', $d->yesterday_profit_amount ?? '0.00');
        $item->setAttribute('yesterday_success_rate', ($d->yesterday_total_count ?? 0) > 0
            ? bcdiv((string)($d->yesterday_success_count ?? 0), (string)($d->yesterday_total_count ?? 0), 4) : '0.0000');
    }

    /**
     * 获取今日与昨日的时间范围
     *
     * @return array  [todayStart, todayEnd, yesterdayStart, yesterdayEnd]
     */
    private function getDailyTimeRange(): array
    {
        $today = Carbon::today();

        return [
            $today->format('Y-m-d 00:00:00'),
            $today->format('Y-m-d 23:59:59'),
            $today->copy()->subDay()->format('Y-m-d 00:00:00'),
            $today->copy()->subDay()->format('Y-m-d 23:59:59'),
        ];
    }

    /**
     * 获取已支付状态的 SQL IN 条件字符串
     *
     * @return string  例如 '"TRADE_SUCCESS","TRADE_REFUND","TRADE_FINISHED","TRADE_FROZEN"'
     */
    private function getPaidStatesCondition(): string
    {
        return '"' . implode('","', [
                Order::TRADE_STATE_SUCCESS,
                Order::TRADE_STATE_REFUND,
                Order::TRADE_STATE_FINISHED,
                Order::TRADE_STATE_FROZEN,
            ]) . '"';
    }
}
