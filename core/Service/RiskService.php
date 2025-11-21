<?php

declare(strict_types = 1);

namespace Core\Service;

use app\model\Blacklist;
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
        try {
            $entityHash = hash('sha3-224', $entityType . $entityValue);

            return Blacklist::where('entity_hash', $entityHash)
                ->where(function ($query) {
                    $query->whereNull('expired_at')
                        ->orWhere('expired_at', '>', Carbon::now()->timezone(config('app.default_timezone')));
                })
                ->exists();
        } catch (Throwable $e) {
            Log::error('黑名单检查异常', [
                'entity_type'  => $entityType,
                'entity_value' => $entityValue,
                'error'        => $e->getMessage()
            ]);
            // 异常情况下为了安全考虑，返回true（拒绝访问）
            return true;
        }
    }

    /**
     * 检查IP是否在黑名单中
     */
    public static function checkIpBlacklist(string $ip): bool
    {
        return self::checkBlacklist(Blacklist::ENTITY_TYPE_IP_ADDRESS, $ip);
    }

    /**
     * 检查用户ID是否在黑名单中
     */
    public static function checkUserIdBlacklist(string $userId): bool
    {
        return self::checkBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $userId);
    }

    /**
     * 检查手机号是否在黑名单中
     */
    public static function checkMobileBlacklist(string $mobile): bool
    {
        return self::checkBlacklist(Blacklist::ENTITY_TYPE_MOBILE, $mobile);
    }

    /**
     * 检查银行卡号是否在黑名单中
     */
    public static function checkBankCardBlacklist(string $bankCard): bool
    {
        return self::checkBlacklist(Blacklist::ENTITY_TYPE_BANK_CARD, $bankCard);
    }

    /**
     * 检查身份证号是否在黑名单中
     */
    public static function checkIdCardBlacklist(string $idCard): bool
    {
        return self::checkBlacklist(Blacklist::ENTITY_TYPE_ID_CARD, $idCard);
    }

    /**
     * 检查设备指纹是否在黑名单中
     */
    public static function checkDeviceFingerprintBlacklist(string $deviceFingerprint): bool
    {
        return self::checkBlacklist(Blacklist::ENTITY_TYPE_DEVICE_FINGERPRINT, $deviceFingerprint);
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
                    'expired_at' => $expiredAt ?? null
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
}
