<?php

declare(strict_types=1);

namespace Core\Service;

use app\model\Blacklist;
use app\model\OrderBuyer;
use app\model\RiskLog;
use InvalidArgumentException;
use support\Log;
use Carbon\Carbon;
use Throwable;

/**
 * 风控服务类
 * 负责各种风控检查，包括IP黑名单、设备指纹等
 */
class RiskService
{
    /**
     * 通用黑名单检查方法
     */
    private static function checkBlacklist(string $entityType, ?string $entityValue): bool
    {
        if (is_null($entityValue)) {
            return false;
        }

        $entityHash = hash('sha3-224', $entityType . $entityValue);
        return Blacklist::select(['id'])->where('entity_hash', $entityHash)->where(function ($query) {
            $query->whereNull('expired_at')->orWhere('expired_at', '>', Carbon::now()->timezone(config('app.default_timezone')));
        })->exists();
    }

    /**
     * 检查IP是否在黑名单中
     */
    public static function checkIpBlacklist(string $ip, int $merchantId): bool
    {
        $isBlack = self::checkBlacklist(Blacklist::ENTITY_TYPE_IP_ADDRESS, $ip);
        if ($isBlack) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => "经系统校验，IP地址“{$ip}”已被列入管控名单，已成功拦截该用户创建订单。"
            ]);
        }
        return $isBlack;
    }

    /**
     * 检查用户ID是否在黑名单中
     */
    public static function checkUserIdBlacklist(int $merchantId, ?string $userId, ?string $buyerOpenId, ?string $tradeNo = null): bool
    {
        $hitId = null;

        if (!empty($userId) && self::checkBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $userId)) {
            $hitId = $userId;
        } elseif (!empty($buyerOpenId) && self::checkBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $buyerOpenId)) {
            $hitId = $buyerOpenId;
        }

        if ($hitId) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => sprintf("发现用户ID“%s”为高风险用户，已成功拦截该用户%s", $hitId, $tradeNo ? "对订单{$tradeNo}进行付款。" : '创建订单。')
            ]);
            return true;
        }

        return false;
    }

    /**
     * 检查手机号是否在黑名单中
     */
    public static function checkMobileBlacklist(string $mobile, int $merchantId, ?string $tradeNo = null): bool
    {
        $isBlack = self::checkBlacklist(Blacklist::ENTITY_TYPE_MOBILE, $mobile);
        if ($isBlack) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => "发现手机号“{$mobile}”为高风险用户，已成功拦截该用户" . ($tradeNo ? "对订单{$tradeNo}进行付款。" : '创建订单。')
            ]);
        }
        return $isBlack;
    }

    /**
     * 检查银行卡号是否在黑名单中
     */
    public static function checkBankCardBlacklist(string $bankCard, int $merchantId, ?string $tradeNo = null): bool
    {
        $isBlack = self::checkBlacklist(Blacklist::ENTITY_TYPE_BANK_CARD, $bankCard);
        if ($isBlack) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => "发现银行卡号“{$bankCard}”为高风险用户，已成功拦截该用户" . ($tradeNo ? "对订单{$tradeNo}进行付款。" : '创建订单。')
            ]);
        }
        return $isBlack;
    }

    /**
     * 检查身份证号是否在黑名单中
     */
    public static function checkIdCardBlacklist(string $idCard, int $merchantId, ?string $tradeNo = null): bool
    {
        $isBlack = self::checkBlacklist(Blacklist::ENTITY_TYPE_ID_CARD, $idCard);
        if ($isBlack) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => "发现身份证号“{$idCard}”为高风险用户，已成功拦截该用户" . ($tradeNo ? "对订单{$tradeNo}进行付款。" : '创建订单。')
            ]);
        }
        return $isBlack;
    }

    /**
     * 检查设备指纹是否在黑名单中
     */
    public static function checkDeviceFingerprintBlacklist(string $deviceFingerprint, int $merchantId): bool
    {
        $isBlack = self::checkBlacklist(Blacklist::ENTITY_TYPE_DEVICE_FINGERPRINT, $deviceFingerprint);
        if ($isBlack) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => "经系统校验，设备“{$deviceFingerprint}”已被列入管控名单，已成功拦截访问。"
            ]);
        }
        return $isBlack;
    }

    /**
     * 批量检查黑名单
     */
    public static function batchCheckBlacklist(array $entities): array
    {
        $results = [];

        foreach ($entities as $entityType => $entityValue) {
            if (in_array($entityType, Blacklist::getSupportedEntityTypes()) && !empty($entityValue)) {
                $results[$entityType] = self::checkBlacklist($entityType, $entityValue);
            }
        }

        return $results;
    }

    /**
     * 支付成功时检查买家是否在黑名单中
     *
     * @param int         $merchantId  商户ID
     * @param string      $tradeNo     交易号
     * @param string|null $ip          买家IP
     * @param string|null $userId      用户ID
     * @param string|null $buyerOpenId 支付渠道买家账户
     * @return bool 命中黑名单返回true，否则返回false
     */
    public static function PaymentedCheck(int $merchantId, string $tradeNo, ?string $ip = null, ?string $userId = null, ?string $buyerOpenId = null): bool
    {
        // 检查IP黑名单
        if (!empty($ip) && self::checkBlacklist(Blacklist::ENTITY_TYPE_IP_ADDRESS, $ip)) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => "支付成功通知：经系统校验，IP地址“{$ip}”已被列入管控名单，已拦截订单{$tradeNo}的下游通知。"
            ]);
            return true;
        }

        // 检查用户ID黑名单
        if (!empty($userId) && self::checkBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $userId)) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => "支付成功通知：发现用户ID“{$userId}”为高风险用户，已拦截订单{$tradeNo}的下游通知。"
            ]);
            return true;
        }

        // 检查支付渠道买家账户黑名单
        if (!empty($buyerOpenId) && self::checkBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $buyerOpenId)) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => "支付成功通知：发现用户ID“{$buyerOpenId}”为高风险用户，已拦截订单{$tradeNo}的下游通知。"
            ]);
            return true;
        }

        return false;
    }

    /**
     * 创建订单时的综合风控检查
     *
     * @param int   $merchantId 商户ID
     * @param array $buyer      买家信息数组，包含 ip, user_id, buyer_open_id, cert_no, cert_type, mobile 等字段
     * @return string|null 返回错误信息字符串，或 null 表示通过检查
     */
    public static function createOrderCheck(int $merchantId, array $buyer): ?string
    {
        $ip          = $buyer['ip'] ?? null;
        $userId      = $buyer['user_id'] ?? null;
        $buyerOpenId = $buyer['buyer_open_id'] ?? null;
        $certNo      = $buyer['cert_no'] ?? null;
        $certType    = $buyer['cert_type'] ?? null;
        $mobile      = $buyer['mobile'] ?? null;

        if (!empty($ip)) {
            if (self::checkIpBlacklist($ip, $merchantId)) {
                return '系统异常，无法完成付款';
            }
            if (self::checkIpOrderLimit($ip)) {
                return '今日支付次数已达上限，请明日再试';
            }
        }
        if (self::checkUserIdBlacklist($merchantId, $userId, $buyerOpenId)) {
            return '系统异常，无法完成付款';
        }
        if (self::checkAccountOrderLimit($userId, $buyerOpenId)) {
            return '今日支付次数已达上限，请明日再试';
        }

        if (!empty($certNo) && $certType === OrderBuyer::CERT_TYPE_IDENTITY_CARD && self::checkIdCardBlacklist($certNo, $merchantId)) {
            return '系统异常，无法完成付款';
        }
        if (!empty($mobile) && self::checkMobileBlacklist($mobile, $merchantId)) {
            return '系统异常，无法完成付款';
        }

        return null;
    }

    /**
     * 通用添加到黑名单方法
     */
    public static function addToBlacklist(string $entityType, string $entityValue, string $reason, string $origin = Blacklist::ORIGIN_MANUAL_REVIEW, ?string $expiredAt = null): bool
    {
        try {
            // 验证实体类型
            if (!in_array($entityType, Blacklist::getSupportedEntityTypes())) {
                throw new InvalidArgumentException("不支持的实体类型: $entityType");
            }

            $entityHash = hash('sha3-224', $entityType . $entityValue);

            // 检查是否已存在
            $existing = Blacklist::where('entity_hash', $entityHash)->first();

            if ($existing) {
                // 更新现有记录
                $existing->update([
                    'reason'     => $reason,
                    'origin'     => $origin,
                    'expired_at' => $expiredAt ?? null
                ]);
            } else {
                // 创建新记录
                Blacklist::create([
                    'entity_type'  => $entityType,
                    'entity_value' => $entityValue,
                    'entity_hash'  => $entityHash,
                    'reason'       => $reason,
                    'origin'       => $origin,
                    'expired_at'   => $expiredAt ?? null
                ]);
            }

            return true;
        } catch (Throwable $e) {
            Log::error('拉黑失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查IP今日订单数是否超过限制
     *
     * @param string $ip 买家IP地址
     * @return bool 超过限制返回true，否则返回false
     */
    public static function checkIpOrderLimit(string $ip): bool
    {
        $limit = sys_config('payment', 'ip_order_limit');
        if (empty($limit) || !is_numeric($limit) || (int)$limit <= 0) {
            return false;
        }

        $todayStart = Carbon::today()->timezone(config('app.default_timezone'));
        $count      = OrderBuyer::where('ip', $ip)->where('created_at', '>=', $todayStart)->count();

        return $count >= (int)$limit;
    }

    /**
     * 检查支付账号今日订单数是否超过限制
     *
     * @return bool 超过限制返回true，否则返回false
     */
    public static function checkAccountOrderLimit(?string $userId, ?string $buyerOpenId): bool
    {

        // 1. 如果两个标识都为空，无需查询数据库，直接视为未超限
        if (empty($userId) && empty($buyerOpenId)) {
            return false;
        }

        // 2. 获取配置并校验
        $limit = sys_config('payment', 'account_order_limit');
        if (empty($limit) || !is_numeric($limit) || (int)$limit <= 0) {
            return false;
        }

        $todayStart = Carbon::today()->timezone(config('app.default_timezone'));

        // 3. 构建查询
        $count = OrderBuyer::query()
            ->where('created_at', '>=', $todayStart)
            ->where(function ($query) use ($userId, $buyerOpenId) {
                // 只有当 userId 不为空时，才加入统计条件
                if (!empty($userId)) {
                    $query->orWhere('user_id', $userId);
                }

                // 只有当 buyerOpenId 不为空时，才加入统计条件
                if (!empty($buyerOpenId)) {
                    $query->orWhere('buyer_open_id', $buyerOpenId);
                }
            })
            ->count();

        return $count >= (int)$limit;
    }
}
