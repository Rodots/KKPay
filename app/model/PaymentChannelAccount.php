<?php

declare(strict_types = 1);

namespace app\model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Model;

/**
 * 支付通道子账户表
 */
class PaymentChannelAccount extends Model
{
    /**
     * 启用软删除。
     */
    use SoftDeletes;

    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'payment_channel_account';

    /**
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'payment_channel_id' => 'integer',
            'rate_mode'          => 'boolean',
            'rate'               => 'decimal:4',
            'min_amount'         => 'decimal:6',
            'max_amount'         => 'decimal:6',
            'daily_limit'        => 'decimal:6',
            'config'             => 'array',
            'status'             => 'boolean',
            'maintenance'        => 'boolean'
        ];
    }

    /**
     * 获取拥有该子账户的支付通道。
     *
     * @return BelongsTo
     */
    public function paymentChannel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class);
    }

    /**
     * 获取该支付通道子账户创建的订单。
     *
     * @return HasMany
     */
    public function order(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
