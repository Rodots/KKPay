<?php

declare(strict_types = 1);

namespace app\model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 订单退款表
 */
class OrderRefund extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'order_refund';

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
            'merchant_id' => 'integer',
            'amount'      => 'decimal:2'
        ];
    }

    // 发起类型枚举
    const string INITIATE_TYPE_ADMIN    = 'admin';
    const string INITIATE_TYPE_API      = 'api';
    const string INITIATE_TYPE_MERCHANT = 'merchant';
    const string INITIATE_TYPE_SYSTEM   = 'system';

    // 退款状态枚举
    const string STATUS_PROCESSING = 'PROCESSING';
    const string STATUS_COMPLETED  = 'COMPLETED';
    const string STATUS_FAILED     = 'FAILED';

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
            $row->id = strtoupper(uniqid('R' . date('y')));
        });
    }

    /**
     * 访问器：操作时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::rawParse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /***
     * 访问器【发起类型文本】
     *
     * @return Attribute
     */
    protected function initiateTypeText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    self::INITIATE_TYPE_ADMIN    => '后台操作',
                    self::INITIATE_TYPE_API      => 'API提交',
                    self::INITIATE_TYPE_MERCHANT => '商户操作',
                    self::INITIATE_TYPE_SYSTEM   => '系统自动',
                ];
                return $enum[$this->getOriginal('initiate_type')] ?? '未知';
            }
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
                    self::STATUS_PROCESSING => '处理中',
                    self::STATUS_COMPLETED  => '已退款',
                    self::STATUS_FAILED     => '退款失败'
                ];
                return $enum[$this->getOriginal('status')] ?? '未知';
            }
        );
    }

    /**
     * 获取当前退款记录对应的订单。
     *
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
