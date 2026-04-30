<?php

declare(strict_types=1);

namespace app\model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 订单投诉表
 */
class OrderComplaint extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'order_complaint';

    /**
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'payment_channel_account_id' => 'integer',
            'merchant_id'                => 'integer',
            'images'                     => 'array',
            'reply_images'               => 'array',
            'negotiate_records'          => 'array',
            'upgrade_time'               => 'datetime',
            'complaint_time'             => 'datetime',
        ];
    }

    /**
     * 为数组 / JSON 序列化准备日期。
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * 可批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'complaint_id',
        'source_api',
        'trade_no',
        'payment_channel_account_id',
        'merchant_id',
        'complaint_reason',
        'complaint_type',
        'status',
        'content',
        'images',
        'reply_content',
        'reply_images',
        'negotiate_records',
        'upgrade_content',
        'upgrade_time',
        'complaint_time',
    ];

    // 投诉状态枚举
    const string STATUS_PENDING             = 'PENDING';

    const string STATUS_MERCHANT_PROCESSING = 'MERCHANT_PROCESSING';

    const string STATUS_MERCHANT_FEEDBACKED = 'MERCHANT_FEEDBACKED';

    const string STATUS_FINISHED            = 'FINISHED';

    const string STATUS_CANCELLED           = 'CANCELLED';

    const string STATUS_CLOSED              = 'CLOSED';

    const string STATUS_UPGRADED            = 'UPGRADED';

    // 投诉状态映射
    const array STATUS_MAP = [
        self::STATUS_PENDING             => '待处理',
        self::STATUS_MERCHANT_PROCESSING => '商户处理中',
        self::STATUS_MERCHANT_FEEDBACKED => '商户已反馈',
        self::STATUS_FINISHED            => '已完结',
        self::STATUS_CANCELLED           => '已撤销',
        self::STATUS_CLOSED              => '已关闭',
        self::STATUS_UPGRADED            => '已升级',
    ];

    /**
     * 访问器：投诉状态文本
     */
    protected function statusText(): Attribute
    {
        return Attribute::make(get: fn() => self::STATUS_MAP[$this->getOriginal('status')] ?? '未知');
    }

    /**
     * 该投诉关联的订单
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'trade_no', 'trade_no');
    }

    /**
     * 该投诉关联的支付通道子账户
     */
    public function paymentChannelAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentChannelAccount::class, 'payment_channel_account_id');
    }

    /**
     * 该投诉关联的商户
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }
}
