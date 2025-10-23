<?php

declare(strict_types = 1);

namespace Core\Service;

use app\model\Blacklist;
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
            $entityHash = hash('sha3-224', $entityValue);

            return Blacklist::where('entity_type', $entityType)
                ->where('entity_hash', $entityHash)
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
     * 综合风控检查
     */
    public static function comprehensiveRiskCheck(array $riskData): array
    {
        $riskResult = [
            'is_risk'       => false,
            'risk_level'    => 0,
            'risk_reasons'  => [],
            'blocked_items' => []
        ];

        try {
            // 定义检查项配置
            $checkItems = [
                'ip'                 => [
                    'method'     => 'checkIpBlacklist',
                    'risk_level' => Blacklist::RISK_LEVEL_HIGH,
                    'reason'     => 'IP地址在黑名单中'
                ],
                'user_id'            => [
                    'method'     => 'checkUserIdBlacklist',
                    'risk_level' => Blacklist::RISK_LEVEL_CRITICAL,
                    'reason'     => '用户ID在黑名单中'
                ],
                'mobile'             => [
                    'method'     => 'checkMobileBlacklist',
                    'risk_level' => Blacklist::RISK_LEVEL_MEDIUM,
                    'reason'     => '手机号在黑名单中'
                ],
                'bank_card'          => [
                    'method'     => 'checkBankCardBlacklist',
                    'risk_level' => Blacklist::RISK_LEVEL_HIGH,
                    'reason'     => '银行卡号在黑名单中'
                ],
                'id_card'            => [
                    'method'     => 'checkIdCardBlacklist',
                    'risk_level' => Blacklist::RISK_LEVEL_HIGH,
                    'reason'     => '身份证号在黑名单中'
                ],
                'device_fingerprint' => [
                    'method'     => 'checkDeviceFingerprintBlacklist',
                    'risk_level' => Blacklist::RISK_LEVEL_MEDIUM,
                    'reason'     => '设备指纹在黑名单中'
                ]
            ];

            // 执行各项检查
            foreach ($checkItems as $key => $config) {
                if (!empty($riskData[$key]) && self::{$config['method']}($riskData[$key])) {
                    $riskResult['is_risk']         = true;
                    $riskResult['risk_level']      = max($riskResult['risk_level'], $config['risk_level']);
                    $riskResult['risk_reasons'][]  = $config['reason'];
                    $riskResult['blocked_items'][] = $key;
                }
            }

            return $riskResult;

        } catch (Throwable $e) {
            Log::error('综合风控检查异常', [
                'risk_data' => $riskData,
                'error'     => $e->getMessage()
            ]);

            // 异常情况下返回高风险
            return [
                'is_risk'       => true,
                'risk_level'    => Blacklist::RISK_LEVEL_CRITICAL,
                'risk_reasons'  => ['风控系统异常'],
                'blocked_items' => ['system']
            ];
        }
    }

    /**
     * 通用添加到黑名单方法
     */
    public static function addToBlacklist(
        string  $entityType,
        string  $entityValue,
        string  $reason,
        int     $riskLevel = 2,
        string  $origin = 'MANUAL_REVIEW',
        ?string $expiredAt = null
    ): bool
    {
        try {
            // 验证实体类型
            if (!in_array($entityType, Blacklist::getSupportedEntityTypes())) {
                throw new \InvalidArgumentException("不支持的实体类型: {$entityType}");
            }

            $entityHash = hash('sha3-224', $entityValue);

            // 检查是否已存在
            $existing = Blacklist::where('entity_type', $entityType)
                ->where('entity_hash', $entityHash)
                ->first();

            if ($existing) {
                // 更新现有记录
                $existing->update([
                    'risk_level' => $riskLevel,
                    'reason'     => $reason,
                    'origin'     => $origin,
                    'expired_at' => $expiredAt
                ]);
            } else {
                // 创建新记录
                Blacklist::create([
                    'entity_type'  => $entityType,
                    'entity_value' => $entityValue,
                    'entity_hash'  => $entityHash,
                    'risk_level'   => $riskLevel,
                    'reason'       => $reason,
                    'origin'       => $origin,
                    'expired_at'   => $expiredAt
                ]);
            }

            return true;

        } catch (Throwable $e) {
            Log::error('添加到黑名单失败', [
                'entity_type'  => $entityType,
                'entity_value' => $entityValue,
                'error'        => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 通用从黑名单移除方法
     */
    public static function removeFromBlacklist(string $entityType, string $entityValue): bool
    {
        try {
            // 验证实体类型
            if (!in_array($entityType, Blacklist::getSupportedEntityTypes())) {
                throw new \InvalidArgumentException("不支持的实体类型: {$entityType}");
            }

            $entityHash = hash('sha3-224', $entityValue);

            $deleted = Blacklist::where('entity_type', $entityType)
                ->where('entity_hash', $entityHash)
                ->delete();

            return $deleted > 0;

        } catch (Throwable $e) {
            Log::error('从黑名单移除失败', [
                'entity_type'  => $entityType,
                'entity_value' => $entityValue,
                'error'        => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 添加IP到黑名单
     */
    public static function addIpToBlacklist(
        string  $ip,
        string  $reason,
        int     $riskLevel = Blacklist::RISK_LEVEL_MEDIUM,
        string  $origin = Blacklist::ORIGIN_MANUAL_REVIEW,
        ?string $expiredAt = null
    ): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_IP_ADDRESS, $ip, $reason, $riskLevel, $origin, $expiredAt);
    }

    /**
     * 添加用户ID到黑名单
     */
    public static function addUserIdToBlacklist(
        string  $userId,
        string  $reason,
        int     $riskLevel = Blacklist::RISK_LEVEL_HIGH,
        string  $origin = Blacklist::ORIGIN_MANUAL_REVIEW,
        ?string $expiredAt = null
    ): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $userId, $reason, $riskLevel, $origin, $expiredAt);
    }

    /**
     * 添加手机号到黑名单
     */
    public static function addMobileToBlacklist(
        string  $mobile,
        string  $reason,
        int     $riskLevel = Blacklist::RISK_LEVEL_MEDIUM,
        string  $origin = Blacklist::ORIGIN_MANUAL_REVIEW,
        ?string $expiredAt = null
    ): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_MOBILE, $mobile, $reason, $riskLevel, $origin, $expiredAt);
    }

    /**
     * 添加银行卡号到黑名单
     */
    public static function addBankCardToBlacklist(
        string  $bankCard,
        string  $reason,
        int     $riskLevel = Blacklist::RISK_LEVEL_HIGH,
        string  $origin = Blacklist::ORIGIN_MANUAL_REVIEW,
        ?string $expiredAt = null
    ): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_BANK_CARD, $bankCard, $reason, $riskLevel, $origin, $expiredAt);
    }

    /**
     * 添加身份证号到黑名单
     */
    public static function addIdCardToBlacklist(
        string  $idCard,
        string  $reason,
        int     $riskLevel = Blacklist::RISK_LEVEL_HIGH,
        string  $origin = Blacklist::ORIGIN_MANUAL_REVIEW,
        ?string $expiredAt = null
    ): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_ID_CARD, $idCard, $reason, $riskLevel, $origin, $expiredAt);
    }

    /**
     * 添加设备指纹到黑名单
     */
    public static function addDeviceFingerprintToBlacklist(
        string  $deviceFingerprint,
        string  $reason,
        int     $riskLevel = Blacklist::RISK_LEVEL_MEDIUM,
        string  $origin = Blacklist::ORIGIN_AUTO_DETECTION,
        ?string $expiredAt = null
    ): bool
    {
        return self::addToBlacklist(Blacklist::ENTITY_TYPE_DEVICE_FINGERPRINT, $deviceFingerprint, $reason, $riskLevel, $origin, $expiredAt);
    }

    /**
     * 从黑名单中移除IP
     */
    public static function removeIpFromBlacklist(string $ip): bool
    {
        return self::removeFromBlacklist(Blacklist::ENTITY_TYPE_IP_ADDRESS, $ip);
    }

    /**
     * 从黑名单中移除用户ID
     */
    public static function removeUserIdFromBlacklist(string $userId): bool
    {
        return self::removeFromBlacklist(Blacklist::ENTITY_TYPE_USER_ID, $userId);
    }

    /**
     * 从黑名单中移除手机号
     */
    public static function removeMobileFromBlacklist(string $mobile): bool
    {
        return self::removeFromBlacklist(Blacklist::ENTITY_TYPE_MOBILE, $mobile);
    }

    /**
     * 从黑名单中移除银行卡号
     */
    public static function removeBankCardFromBlacklist(string $bankCard): bool
    {
        return self::removeFromBlacklist(Blacklist::ENTITY_TYPE_BANK_CARD, $bankCard);
    }

    /**
     * 从黑名单中移除身份证号
     */
    public static function removeIdCardFromBlacklist(string $idCard): bool
    {
        return self::removeFromBlacklist(Blacklist::ENTITY_TYPE_ID_CARD, $idCard);
    }

    /**
     * 从黑名单中移除设备指纹
     */
    public static function removeDeviceFingerprintFromBlacklist(string $deviceFingerprint): bool
    {
        return self::removeFromBlacklist(Blacklist::ENTITY_TYPE_DEVICE_FINGERPRINT, $deviceFingerprint);
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
     * 获取黑名单统计信息
     */
    public static function getBlacklistStats(): array
    {
        try {
            $counts = Blacklist::whereIn('entity_type', Blacklist::getSupportedEntityTypes())
                ->where(function ($query) {
                    $query->whereNull('expired_at')
                        ->orWhere('expired_at', '>', Carbon::now()->timezone(config('app.default_timezone')));
                })
                ->groupBy('entity_type')
                ->selectRaw('entity_type, count(*) as count')
                ->pluck('count', 'entity_type');

            $stats = [];
            $total = 0;
            foreach (Blacklist::getSupportedEntityTypes() as $entityType) {
                $count              = $counts[$entityType] ?? 0;
                $stats[$entityType] = $count;
                $total              += $count;
            }

            $stats['total'] = $total;

            return $stats;

        } catch (Throwable $e) {
            Log::error('获取黑名单统计信息失败', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
