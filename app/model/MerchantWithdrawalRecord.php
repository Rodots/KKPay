<?php

declare(strict_types=1);

namespace app\model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 商户提款记录表
 */
class MerchantWithdrawalRecord extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_withdrawal_record';

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
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'merchant_id'      => 'integer',
            'payee_info'       => 'array',
            'amount'           => 'decimal:2',
            'prepaid_deducted' => 'decimal:2',
            'received_amount'  => 'decimal:2',
            'fee'              => 'decimal:2',
            'fee_type'         => 'boolean'
        ];
    }

    /**
     * 可批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'merchant_id',
        'payee_info',
        'amount',
        'prepaid_deducted',
        'received_amount',
        'fee',
        'fee_type',
        'status',
        'reject_reason',
    ];

    // 提款状态枚举
    const string STATUS_PENDING    = 'PENDING';
    const string STATUS_PROCESSING = 'PROCESSING';
    const string STATUS_COMPLETED  = 'COMPLETED';
    const string STATUS_FAILED     = 'FAILED';
    const string STATUS_REJECTED   = 'REJECTED';
    const string STATUS_CANCELED   = 'CANCELED';

    /**
     * 模型启动方法，用于注册模型事件
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($row) {
            // 组合：业务类型(1) + 年份(2) + uniqid(13) = 16位
            $row->id = strtoupper(uniqid('W' . date('y')));
        });
    }

    /**
     * 访问器：创建时间
     *
     * @return Attribute
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：更新时间
     *
     * @return Attribute
     */
    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /***
     * 访问器【状态文本】
     *
     * @return Attribute
     */
    protected function statusText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::STATUS_PENDING    => '待处理',
                    self::STATUS_PROCESSING => '处理中',
                    self::STATUS_COMPLETED  => '已完成',
                    self::STATUS_FAILED     => '提款失败',
                    self::STATUS_REJECTED   => '已被驳回',
                    self::STATUS_CANCELED   => '已取消',
                ];
                return $enum[$this->getOriginal('status')] ?? '未知';
            }
        );
    }

    /***
     * 访问器【服务费收取方式文本】
     *
     * @return Attribute
     */
    protected function feeTypeText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    0 => '在提款金额内扣除',
                    1 => '在可用余额额外扣除',
                ];
                return $enum[$this->getOriginal('fee_type')] ?? '未知';
            }
        );
    }

    /**
     * 关联商户
     *
     * @return BelongsTo
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }
}
