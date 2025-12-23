<?php

declare(strict_types=1);

namespace Core\Service;

use app\model\PaymentChannel;
use app\model\PaymentChannelAccount;
use Core\Exception\PaymentException;
use support\Redis;
use Carbon\Carbon;

/**
 * 支付通道选择服务类
 */
class PaymentChannelSelectionService
{
    /**
     * Redis键前缀常量
     */
    const string REDIS_KEY_DAILY_LIMIT_PREFIX = 'PaymentDailyLimit:';

    /**
     * 根据支付通道编码选择支付通道
     *
     * 此方法用于指定特定通道编码的支付场景，会验证通道状态、风控规则，
     * 然后根据通道配置的轮询策略选择最合适的子账户
     *
     * @param string $channelCode 支付通道编码
     * @param string $paymentType 支付方式类型
     * @param string $amount      支付金额
     * @return PaymentChannelAccount 选中的支付通道子账户
     * @throws PaymentException 当通道不可用或无可用子账户时抛出异常
     */
    public static function selectByCode(
        string $channelCode,
        string $paymentType,
        string $amount
    ): PaymentChannelAccount
    {
        // 查找指定的支付通道并验证基础状态
        $paymentChannel = PaymentChannel::where('code', $channelCode)
            ->where('payment_type', $paymentType)
            ->where('status', true)
            ->first();

        if (!$paymentChannel) {
            throw new PaymentException('支付通道不可用');
        }

        // 验证通道级别的风控规则（金额限制、时间段、日限额等）
        self::validateChannelRiskControl($paymentChannel, $amount);

        // 使用优化查询选择可用的子账户
        $paymentChannelAccount = self::selectAvailableAccount($paymentChannel, $amount);

        if (!$paymentChannelAccount) {
            throw new PaymentException('暂无可用的收款账户');
        }

        return $paymentChannelAccount;
    }

    /**
     * 根据支付方式选择支付通道
     *
     * 此方法用于自动选择支付通道的场景，会遍历该支付方式下的所有可用通道，
     * 找到第一个通过风控校验且有可用子账户的通道
     *
     * @param string $paymentType 支付方式类型
     * @param string $amount      支付金额
     * @return PaymentChannelAccount 选中的支付通道子账户
     * @throws PaymentException 当支付方式不可用或无可用子账户时抛出异常
     */
    public static function selectByType(string $paymentType, string $amount): PaymentChannelAccount
    {
        // 查找该支付方式下所有启用的支付通道
        $paymentChannels = PaymentChannel::where('payment_type', $paymentType)
            ->where('status', true)
            ->get();

        if ($paymentChannels->isEmpty()) {
            throw new PaymentException('该支付方式暂不可用');
        }

        // 遍历通道，寻找第一个可用的通道和子账户组合
        /** @var PaymentChannel $channel */
        foreach ($paymentChannels as $channel) {
            try {
                // 验证通道级别的风控规则
                self::validateChannelRiskControl($channel, $amount);

                // 尝试从该通道选择可用的子账户
                $paymentChannelAccount = self::selectAvailableAccount($channel, $amount);

                if ($paymentChannelAccount) {
                    // 设置关联关系，便于后续使用
                    $paymentChannelAccount->setRelation('paymentChannel', $channel);
                    return $paymentChannelAccount;
                }
            } catch (PaymentException $e) {
                // 对于金额或时间限制异常，跳过当前通道尝试下一个
                // 对于其他类型异常（如系统错误），直接向上抛出
                if (str_contains($e->getMessage(), '支付金额不能') || str_contains($e->getMessage(), '不在可用时间段内')) {
                    continue;
                }
                throw $e;
            }
        }

        throw new PaymentException('暂无可用的收款账户');
    }

    /**
     * 验证通道级别的风控规则
     *
     * 检查支付通道的各项限制条件，包括金额限制、时间段限制和日限额控制
     *
     * @param PaymentChannel $paymentChannel 支付通道对象
     * @param string         $amount         支付金额
     * @throws PaymentException 当不满足风控条件时抛出异常
     */
    private static function validateChannelRiskControl(PaymentChannel $paymentChannel, string $amount): void
    {
        // 检查通道最小金额限制
        if ($paymentChannel->min_amount !== null && bccomp($amount, (string)$paymentChannel->min_amount, 2) < 0) {
            throw new PaymentException("支付金额不能小于 $paymentChannel->min_amount 元");
        }

        // 检查通道最大金额限制
        if ($paymentChannel->max_amount !== null && bccomp($amount, (string)$paymentChannel->max_amount, 2) > 0) {
            throw new PaymentException("支付金额不能大于 $paymentChannel->max_amount 元");
        }

        // 检查通道可用时间段
        if (!self::isWithinTimeRange($paymentChannel->earliest_time, $paymentChannel->latest_time)) {
            throw new PaymentException('当前时间不在可用时间段内');
        }

        // 检查通道日限额
        if ($paymentChannel->daily_limit !== null) {
            $todayUsed = self::getTodayUsedAmount($paymentChannel->id);
            if (bccomp(bcadd($todayUsed, $amount, 2), (string)$paymentChannel->daily_limit, 2) > 0) {
                throw new PaymentException('超出通道日限额');
            }
        }
    }

    /**
     * 选择可用的支付通道账户
     *
     * @param PaymentChannel $paymentChannel 支付通道对象
     * @param string         $amount         支付金额
     * @return PaymentChannelAccount|null 选中的子账户，无可用账户时返回null
     */
    private static function selectAvailableAccount(PaymentChannel $paymentChannel, string $amount): ?PaymentChannelAccount
    {
        // 构建基础查询：只查询启用且非维护状态的账户
        $query = $paymentChannel->paymentChannelAccount()
            ->where('status', true)
            ->where('maintenance', false);

        // 在SQL层添加金额限制条件，利用数据库索引提升性能
        self::addAmountConstraintsToQuery($query, $amount);

        // 在SQL层添加时间限制条件
        self::addTimeConstraintsToQuery($query);

        // 执行查询，按ID排序确保结果一致性
        $accounts = $query->orderBy('id')->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        // 过滤日限额（Redis数据无法在SQL中处理，需在PHP层过滤）
        $availableAccounts = $accounts->filter(function ($account) use ($amount) {
            if ($account->daily_limit !== null) {
                $todayUsed = self::getTodayUsedAmount($account->id, true);
                if (bccomp(bcadd($todayUsed, $amount, 2), (string)$account->daily_limit, 2) > 0) {
                    return false;
                }
            }
            return true;
        });

        if ($availableAccounts->isEmpty()) {
            return null;
        }

        // 根据通道配置的轮询模式选择最终账户
        return self::selectAccountByStrategy($paymentChannel->roll_mode, $availableAccounts, $paymentChannel->id);
    }

    /**
     * 添加金额限制条件到数据库查询中
     *
     * 根据inherit_config字段决定是否检查子账户自身的金额限制：
     * - inherit_config=1：继承父通道规则，不检查子账户金额限制
     * - inherit_config=0：使用子账户自身规则，需检查min_amount和max_amount
     *
     * @param mixed  $query  查询构建器
     * @param string $amount 支付金额
     * @return void
     */
    private static function addAmountConstraintsToQuery(mixed $query, string $amount): void
    {
        $query->where(function ($q) use ($amount) {
            $q->where(function ($subQ) use ($amount) {
                // 继承配置的账户：跳过自身金额限制检查
                $subQ->where('inherit_config', true);
            })->orWhere(function ($subQ) use ($amount) {
                // 不继承配置的账户：必须满足自身金额限制
                $subQ->where('inherit_config', false)
                    ->where(function ($amountQ) use ($amount) {
                        // 最小金额检查：为空或小于等于支付金额
                        $amountQ->whereNull('min_amount')
                            ->orWhere('min_amount', '<=', $amount);
                    })
                    ->where(function ($amountQ) use ($amount) {
                        // 最大金额检查：为空或大于等于支付金额
                        $amountQ->whereNull('max_amount')
                            ->orWhere('max_amount', '>=', $amount);
                    });
            });
        });
    }

    /**
     * 添加时间限制条件到数据库查询中
     *
     * 处理子账户的时间段限制：
     * - 完全无时间限制：earliest_time和latest_time都为空
     * - earliest_time为空：视为从00:00开始可用
     * - latest_time为空：视为到23:59都可用
     * - 都不为空：在指定时间段内可用
     *
     * @param mixed $query 查询构建器
     * @return void
     */
    private static function addTimeConstraintsToQuery(mixed $query): void
    {
        $currentTime = Carbon::now()->timezone(config('app.default_timezone'))->format('H:i');

        $query->where(function ($q) use ($currentTime) {
            $q->where(function ($timeQ) {
                // 完全无时间限制：两个时间字段都为空
                $timeQ->whereNull('earliest_time')
                    ->whereNull('latest_time');
            })->orWhere(function ($timeQ) use ($currentTime) {
                // earliest_time为空，latest_time不为空：从00:00到latest_time
                $timeQ->whereNull('earliest_time')
                    ->whereNotNull('latest_time')
                    ->where('latest_time', '>=', $currentTime);
            })->orWhere(function ($timeQ) use ($currentTime) {
                // earliest_time不为空，latest_time为空：从earliest_time到23:59
                $timeQ->whereNotNull('earliest_time')
                    ->whereNull('latest_time')
                    ->where('earliest_time', '<=', $currentTime);
            })->orWhere(function ($timeQ) use ($currentTime) {
                // 两个时间都不为空：在指定时间段内
                $timeQ->whereNotNull('earliest_time')
                    ->whereNotNull('latest_time')
                    ->where('earliest_time', '<=', $currentTime)
                    ->where('latest_time', '>=', $currentTime);
            });
        });
    }

    /**
     * 根据轮询策略选择账户
     *
     * 支持四种轮询模式，当模式不匹配时默认使用顺序轮询：
     * - 0: 按顺序依次轮询（记录上次使用账户）
     * - 1: 随机轮询
     * - 2: 按权重随机轮询
     * - 3: 仅使用第一个可用账户
     *
     * @param int   $rollMode  轮询模式
     * @param mixed $accounts  可用账户集合
     * @param int   $channelId 通道ID
     * @return PaymentChannelAccount|null 选中的账户
     */
    private static function selectAccountByStrategy(int $rollMode, mixed $accounts, int $channelId): ?PaymentChannelAccount
    {
        return match ($rollMode) {
            PaymentChannel::ROLL_MODE_RANDOM => self::selectAccountByRandom($accounts),
            PaymentChannel::ROLL_MODE_WEIGHT => self::selectAccountByWeight($accounts, $channelId),
            PaymentChannel::ROLL_MODE_FIRST => self::selectFirstAvailableAccount($accounts),
            default => self::selectAccountByOrder($accounts, $channelId), // 默认使用顺序轮询
        };
    }

    /**
     * 按顺序轮询选择账户
     *
     * 记录上次使用的子账户ID，下次选择时顺序+1（循环选择）。
     * 使用Redis存储轮询状态，确保多进程环境下的一致性。
     *
     * @param mixed $accounts  可用账户集合
     * @param int   $channelId 通道ID，用于Redis键
     * @return PaymentChannelAccount|null 选中的账户
     */
    private static function selectAccountByOrder(mixed $accounts, int $channelId): ?PaymentChannelAccount
    {
        if ($accounts->isEmpty()) {
            return null;
        }

        // 从Redis获取上次使用的账户ID
        $redisKey   = 'PaymentChannelAccountSort:' . $channelId;
        $lastUsedId = Redis::get($redisKey);

        // 根据上次使用的账户确定下一个账户
        if ($lastUsedId) {
            $lastIndex = $accounts->search(fn($account) => $account->id == $lastUsedId);
            if ($lastIndex !== false) {
                // 找到上次使用的账户，选择下一个（循环）
                $nextIndex       = ($lastIndex + 1) % $accounts->count();
                $selectedAccount = $accounts[$nextIndex];
            } else {
                // 上次使用的账户不在当前可用列表中，重新从第一个开始
                $selectedAccount = $accounts->first();
            }
        } else {
            // 首次使用该通道，选择第一个账户
            $selectedAccount = $accounts->first();
        }

        // 记录本次使用的账户ID到Redis，24小时过期
        Redis::setex($redisKey, 86400, $selectedAccount->id);

        return $selectedAccount;
    }

    /**
     * 随机轮询选择账户
     *
     * 从所有可用账户中完全随机选择一个，每个账户被选中的概率相等。
     *
     * @param mixed $accounts 可用账户集合
     * @return PaymentChannelAccount|null 随机选中的账户
     */
    private static function selectAccountByRandom(mixed $accounts): ?PaymentChannelAccount
    {
        return $accounts->isEmpty() ? null : $accounts->random();
    }

    /**
     * 按权重随机选择账户
     *
     * 基于roll_weight字段进行概率选择，权重越高被选中概率越大。
     * 权重为0的账户会被排除，如果所有账户权重都为0则降级为顺序轮询。
     *
     * @param mixed $accounts  可用账户集合
     * @param int   $channelId 通道ID，用于降级轮询
     * @return PaymentChannelAccount|null 按权重选中的账户
     */
    private static function selectAccountByWeight(mixed $accounts, int $channelId): ?PaymentChannelAccount
    {
        if ($accounts->isEmpty()) {
            return null;
        }

        // 过滤出权重大于0的账户
        $weightedAccounts = $accounts->filter(fn($account) => $account->roll_weight > 0);

        // 如果所有账户权重都为0，降级为按顺序轮询
        if ($weightedAccounts->isEmpty()) {
            return self::selectAccountByOrder($accounts, $channelId);
        }

        // 计算总权重并生成随机数
        $totalWeight  = $weightedAccounts->sum('roll_weight');
        $randomNumber = mt_rand(1, (int)$totalWeight);

        // 轮盘赌算法选择账户
        /** @var PaymentChannelAccount $account */
        foreach ($weightedAccounts as $account) {
            $randomNumber -= $account->roll_weight;
            if ($randomNumber <= 0) {
                return $account;
            }
        }

        // 理论上不会到达这里，作为安全保障返回最后一个
        return $weightedAccounts->last();
    }

    /**
     * 选择第一个可用账户
     *
     * 直接返回账户列表中的第一个账户，适用于固定使用特定账户的场景。
     *
     * @param mixed $accounts 可用账户集合
     * @return PaymentChannelAccount|null 第一个账户
     */
    private static function selectFirstAvailableAccount(mixed $accounts): ?PaymentChannelAccount
    {
        return $accounts->isEmpty() ? null : $accounts->first();
    }

    /**
     * 检查当前时间是否在指定时间范围内
     *
     * @param string|null $earliestTime 最早可用时间（HH:MM格式）
     * @param string|null $latestTime   最晚可用时间（HH:MM格式）
     * @return bool 是否在时间范围内
     */
    private static function isWithinTimeRange(?string $earliestTime, ?string $latestTime): bool
    {
        // 如果两个时间都没有设置，则始终可用
        if (!$earliestTime && !$latestTime) {
            return true;
        }

        $now = Carbon::now()->timezone(config('app.default_timezone'))->format('H:i');

        // 处理各种时间限制情况
        $startTime = $earliestTime ?: '00:00';  // 最早时间为空时视为00:00
        $endTime   = $latestTime ?: '23:59';      // 最晚时间为空时视为23:59

        // 当前时间在时间区间内
        return $now >= $startTime && $now <= $endTime;
    }

    /**
     * 获取今日已使用金额
     *
     * 从Redis中获取通道或账户今日已使用的金额统计，用于日限额控制。
     *
     * @param int  $id        通道ID或账户ID
     * @param bool $isAccount 是否为账户ID（false为通道ID）
     * @return string 今日已使用金额
     */
    private static function getTodayUsedAmount(int $id, bool $isAccount = false): string
    {
        $redisKey = self::REDIS_KEY_DAILY_LIMIT_PREFIX . ($isAccount ? 'account:' : 'channel:') . $id . ':' . date('Y-m-d');
        $used     = Redis::get($redisKey);
        return $used ? (string)$used : '0.00';
    }

    /**
     * 更新今日使用金额
     *
     * 在订单创建成功后调用此方法，更新通道和账户的日使用金额统计。
     *
     * @param int    $channelId 通道ID
     * @param int    $accountId 账户ID
     * @param string $amount    本次使用金额
     */
    public static function updateTodayUsedAmount(int $channelId, int $accountId, string $amount): void
    {
        $today      = date('Y-m-d');
        $channelKey = self::REDIS_KEY_DAILY_LIMIT_PREFIX . 'channel:' . $channelId . ':' . $today;
        $accountKey = self::REDIS_KEY_DAILY_LIMIT_PREFIX . 'account:' . $accountId . ':' . $today;

        // 批量操作更新使用金额
        Redis::incrbyfloat($channelKey, (float)$amount);  // 增加通道使用金额
        Redis::expire($channelKey, 86400);   // 设置过期时间
        Redis::incrbyfloat($accountKey, (float)$amount);  // 增加账户使用金额
        Redis::expire($accountKey, 86400);   // 设置过期时间
    }
}
