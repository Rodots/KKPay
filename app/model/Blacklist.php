<?php

declare(strict_types = 1);

namespace app\model;

use support\Model;

/**
 * 用户/客户黑名单表
 */
class Blacklist extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'blacklist';

    /**
     * 禁用自动写入updated_at
     *
     * @var null
     */
    const null UPDATED_AT = null;

    /**
     * 获取应该转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'risk_level' => 'integer',
            'expired_at' => 'timestamp'
        ];
    }

    /**
     * 实体类型常量枚举
     */
    public const string ENTITY_TYPE_USER_ID            = 'USER_ID';
    public const string ENTITY_TYPE_BANK_CARD          = 'BANK_CARD';
    public const string ENTITY_TYPE_ID_CARD            = 'ID_CARD';
    public const string ENTITY_TYPE_MOBILE             = 'MOBILE';
    public const string ENTITY_TYPE_IP_ADDRESS         = 'IP_ADDRESS';
    public const string ENTITY_TYPE_DEVICE_FINGERPRINT = 'DEVICE_FINGERPRINT';

    /**
     * 风险等级常量
     */
    public const int RISK_LEVEL_LOW      = 1;
    public const int RISK_LEVEL_MEDIUM   = 2;
    public const int RISK_LEVEL_HIGH     = 3;
    public const int RISK_LEVEL_CRITICAL = 4;

    /**
     * 黑名单来源常量
     */
    public const string ORIGIN_MANUAL_REVIEW  = 'MANUAL_REVIEW';
    public const string ORIGIN_AUTO_DETECTION = 'AUTO_DETECTION';
    public const string ORIGIN_THIRD_PARTY    = 'THIRD_PARTY';
    public const string ORIGIN_SYSTEM_ALERT   = 'SYSTEM_ALERT';

    /**
     * 获取所有支持的实体类型
     */
    public static function getSupportedEntityTypes(): array
    {
        return [
            self::ENTITY_TYPE_USER_ID,
            self::ENTITY_TYPE_BANK_CARD,
            self::ENTITY_TYPE_ID_CARD,
            self::ENTITY_TYPE_MOBILE,
            self::ENTITY_TYPE_IP_ADDRESS,
            self::ENTITY_TYPE_DEVICE_FINGERPRINT,
        ];
    }
}
