<?php

declare(strict_types=1);

namespace app\model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 订单买家表
 */
class OrderBuyer extends Model
{

    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'order_buyer';

    /**
     * 与表关联的主键。
     *
     * @var string
     */
    protected $primaryKey = 'trade_no';

    /**
     * 指示模型的 ID 是否是自动递增的。
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * 主键 ID 的数据类型。
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * 可以被批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'trade_no',
        'ip',
        'user_agent',
        'user_id',
        'real_name',
        'cert_no',
        'cert_type',
        'min_age',
        'mobile'
    ];

    /**
     * 序列化时应隐藏的属性。
     *
     * @var array<string>
     */
    protected $hidden = ['trade_no', 'created_at', 'updated_at'];

    // 证件类型枚举
    const string CERT_TYPE_IDENTITY_CARD       = 'IDENTITY_CARD'; // 居民身份证
    const string CERT_TYPE_PASSPORT            = 'PASSPORT'; // 护照
    const string CERT_TYPE_OFFICER_CARD        = 'OFFICER_CARD'; // 军官证
    const string CERT_TYPE_SOLDIER_CARD        = 'SOLDIER_CARD'; // 士兵证
    const string CERT_TYPE_HOKOU               = 'HOKOU'; // 户口簿
    const string CERT_TYPE_PERMANENT_RESIDENCE = 'PERMANENT_RESIDENCE_FOREIGNER'; // 外国人永久居留身份证

    /**
     * 访问器：创建时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：更新时间
     */
    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /***
     * 访问器：证件类型文本
     *
     * @return Attribute
     */
    protected function certTypeText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::CERT_TYPE_IDENTITY_CARD       => '居民身份证',
                    self::CERT_TYPE_PASSPORT            => '护照',
                    self::CERT_TYPE_OFFICER_CARD        => '军官证',
                    self::CERT_TYPE_SOLDIER_CARD        => '士兵证',
                    self::CERT_TYPE_HOKOU               => '户口簿',
                    self::CERT_TYPE_PERMANENT_RESIDENCE => '外国人永久居留身份证',
                ];
                return $enum[$this->getOriginal('cert_type')] ?? '未知';
            }
        );
    }

    /**
     * 该买家属于这个订单
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'trade_no', 'trade_no');
    }

    /**
     * 检查证件类型是否合法
     *
     * @param string|null $certType 证件类型
     * @return bool
     */
    public static function isValidCertType(?string $certType): bool
    {
        if ($certType === null) {
            return true;
        }

        return in_array($certType, [
            self::CERT_TYPE_IDENTITY_CARD,
            self::CERT_TYPE_PASSPORT,
            self::CERT_TYPE_OFFICER_CARD,
            self::CERT_TYPE_SOLDIER_CARD,
            self::CERT_TYPE_HOKOU,
            self::CERT_TYPE_PERMANENT_RESIDENCE,
        ], true);
    }
}
