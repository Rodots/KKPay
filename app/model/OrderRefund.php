<?php

declare(strict_types = 1);

namespace app\model;

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
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'merchant_id'            => 'integer',
            'amount'                 => 'decimal:2',
            'admin_id'               => 'integer',
            'notify_state'           => 'boolean',
            'notify_retry_count'     => 'integer',
            'notify_next_retry_time' => 'integer'
        ];
    }

    // 发起类型枚举
    const string INITIATE_TYPE_ADMIN    = 'admin';
    const string INITIATE_TYPE_API      = 'api';
    const string INITIATE_TYPE_MERCHANT = 'merchant';
    const string INITIATE_TYPE_SYSTEM   = 'system';

    // 退款状态枚举
    const string STATUS_PENDING    = 'PENDING';
    const string STATUS_PROCESSING = 'PROCESSING';
    const string STATUS_COMPLETED  = 'COMPLETED';
    const string STATUS_FAILED     = 'FAILED';
    const string STATUS_REJECTED   = 'REJECTED';
    const string STATUS_CANCELED   = 'CANCELED';

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
                    self::INITIATE_TYPE_MERCHANT => '商户提交',
                    self::INITIATE_TYPE_SYSTEM   => '系统自动化',
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
                    self::STATUS_PENDING    => '待处理',
                    self::STATUS_PROCESSING => '处理中',
                    self::STATUS_COMPLETED  => '已完成',
                    self::STATUS_FAILED     => '退款失败',
                    self::STATUS_REJECTED   => '已被驳回',
                    self::STATUS_CANCELED   => '已取消',
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
