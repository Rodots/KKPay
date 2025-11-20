<?php

declare(strict_types = 1);

namespace app\model;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'entity_type',
        'entity_value',
        'entity_hash',
        'reason',
        'origin',
        'expired_at',
    ];

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
     * 黑名单来源常量
     */
    public const string ORIGIN_MANUAL_REVIEW  = 'MANUAL_REVIEW';
    public const string ORIGIN_AUTO_DETECTION = 'AUTO_DETECTION';
    public const string ORIGIN_THIRD_PARTY    = 'THIRD_PARTY';
    public const string ORIGIN_SYSTEM_ALERT    = 'SYSTEM_ALERT';
    public const string ORIGIN_MERCHANT_REPORT = 'MERCHANT_REPORT';

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

    /**
     * 获取所有支持的来源
     */
    public static function getSupportedOrigins(): array
    {
        return [
            self::ORIGIN_MANUAL_REVIEW,
            self::ORIGIN_AUTO_DETECTION,
            self::ORIGIN_THIRD_PARTY,
            self::ORIGIN_SYSTEM_ALERT,
            self::ORIGIN_MERCHANT_REPORT,
        ];
    }

    /***
     * 访问器：实体类型文本
     *
     * @return Attribute
     */
    protected function entityTypeText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::ENTITY_TYPE_USER_ID            => '用户ID',
                    self::ENTITY_TYPE_BANK_CARD          => '银行卡号',
                    self::ENTITY_TYPE_ID_CARD            => '身份证号',
                    self::ENTITY_TYPE_MOBILE             => '手机号',
                    self::ENTITY_TYPE_IP_ADDRESS         => 'IP地址',
                    self::ENTITY_TYPE_DEVICE_FINGERPRINT => '设备指纹',
                ];
                return $enum[$this->getOriginal('entity_type')] ?? '未知';
            }
        );
    }

    /***
     * 访问器：来源文本
     *
     * @return Attribute
     */
    protected function originText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::ORIGIN_MANUAL_REVIEW   => '人工审核',
                    self::ORIGIN_AUTO_DETECTION  => '自动检测',
                    self::ORIGIN_THIRD_PARTY     => '第三方',
                    self::ORIGIN_SYSTEM_ALERT    => '系统提醒',
                    self::ORIGIN_MERCHANT_REPORT => '商户报备',
                ];
                return $enum[$this->getOriginal('origin')] ?? '未知';
            }
        );
    }
}
