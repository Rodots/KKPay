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
    private static function checkBlacklist(string $entityType, string $entityValue): bool
    {
        $entityHash = hash('sha3-224', $entityType . $entityValue);
        return Blacklist::where('entity_hash', $entityHash)->where(function ($query) {
            $query->whereNull('expired_at')
                ->orWhere('expired_at', '>', Carbon::now()->timezone(config('app.default_timezone')));
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
    public static function checkUserIdBlacklist(string $userId, int $merchantId, ?string $tradeNo = null): bool
    {
        $isBlack = self::checkBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $userId);
        if ($isBlack) {
            RiskLog::create([
                'merchant_id' => $merchantId,
                'type'        => RiskLog::TYPE_BLACKLIST,
                'content'     => "发现用户ID“{$userId}”为高风险用户，已成功拦截该用户" . ($tradeNo ? "对订单{$tradeNo}进行付款。" : '创建订单。')
            ]);
        }
        return $isBlack;
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
     * 添加IP到黑名单
     */
    public static function addIpToBlacklist(string $ip, string $reason, string $origin = Blacklist::ORIGIN_MANUAL_REVIEW, ?string $expiredAt = null): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_IP_ADDRESS, $ip, $reason, $origin, $expiredAt);
    }

    /**
     * 添加用户ID到黑名单
     */
    public static function addUserIdToBlacklist(string $userId, string $reason, string $origin = Blacklist::ORIGIN_MANUAL_REVIEW, ?string $expiredAt = null): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $userId, $reason, $origin, $expiredAt);
    }

    /**
     * 添加手机号到黑名单
     */
    public static function addMobileToBlacklist(string $mobile, string $reason, string $origin = Blacklist::ORIGIN_MANUAL_REVIEW, ?string $expiredAt = null): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_MOBILE, $mobile, $reason, $origin, $expiredAt);
    }

    /**
     * 添加银行卡号到黑名单
     */
    public static function addBankCardToBlacklist(string $bankCard, string $reason, string $origin = Blacklist::ORIGIN_MANUAL_REVIEW, ?string $expiredAt = null): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_BANK_CARD, $bankCard, $reason, $origin, $expiredAt);
    }

    /**
     * 添加身份证号到黑名单
     */
    public static function addIdCardToBlacklist(string $idCard, string $reason, string $origin = Blacklist::ORIGIN_MANUAL_REVIEW, ?string $expiredAt = null): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_ID_CARD, $idCard, $reason, $origin, $expiredAt);
    }

    /**
     * 添加设备指纹到黑名单
     */
    public static function addDeviceFingerprintToBlacklist(string $deviceFingerprint, string $reason, string $origin = Blacklist::ORIGIN_AUTO_DETECTION, ?string $expiredAt = null): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_DEVICE_FINGERPRINT, $deviceFingerprint, $reason, $origin, $expiredAt);
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
        $count = OrderBuyer::where('ip', $ip)
            ->where('created_at', '>=', $todayStart)
            ->count();

        return $count >= (int)$limit;
    }

    /**
     * 检查支付账号今日订单数是否超过限制
     *
     * @param string|null $userId 支付账号/用户ID
     * @return bool 超过限制返回true，否则返回false
     */
    public static function checkAccountOrderLimit(?string $userId): bool
    {
        if (empty($userId)) {
            return false;
        }

        $limit = sys_config('payment', 'account_order_limit');
        if (empty($limit) || !is_numeric($limit) || (int)$limit <= 0) {
            return false;
        }

        $todayStart = Carbon::today()->timezone(config('app.default_timezone'));
        $count = OrderBuyer::where('user_id', $userId)
            ->where('created_at', '>=', $todayStart)
            ->count();

        return $count >= (int)$limit;
    }
}
