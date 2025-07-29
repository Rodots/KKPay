<?php

declare(strict_types = 1);

namespace app\model;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Model;

/**
 * 支付通道表
 */
class PaymentChannel extends Model
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
    protected $table = 'payment_channel';

    /**
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'costs'        => 'decimal:4',
            'fixed_costs'  => 'decimal:6',
            'rate'         => 'decimal:4',
            'fixed_fee'    => 'decimal:6',
            'min_fee'      => 'decimal:6',
            'max_fee'      => 'decimal:6',
            'min_amount'   => 'decimal:6',
            'max_amount'   => 'decimal:6',
            'daily_limit'  => 'decimal:6',
            'roll_mode'    => 'integer',
            'settle_cycle' => 'integer',
            'status'       => 'boolean'
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
     * 获取器【发起类型文本】
     *
     * @return Attribute
     */
    protected function InitiateTypeText(): Attribute
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
     * 获取器【状态文本】
     *
     * @return Attribute
     */
    protected function StatusText(): Attribute
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

    /***
     * 获取器【结算周期文本】
     *
     * @return Attribute
     */
    protected function SettleCycleText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    0  => '【实时】笔笔结算',
                    1  => '【D0】即日结算',
                    2  => '【D1】次日结算',
                    3  => '【D2】隔日结算',
                    4  => '【T0】工作日即日结算',
                    5  => '【T1】工作日次日结算',
                    6  => '【T2】工作日隔日结算',
                    7  => '【D3】交易后3个自然日结算',
                    8  => '【D7】交易后7个自然日结算',
                    9  => '【D14】交易后14个自然日结算',
                    10 => '【D30】交易后30个自然日结算',
                    11 => '【T3】交易后3个工作日结算',
                    12 => '【T7】交易后7个工作日结算',
                    13 => '【T14】交易后14个工作日结算',
                    14 => '【T30】交易后30个工作日结算',
                    15 => '【测试】直接吃单不结算'
                ];
                return $enum[$this->getOriginal('settle_cycle')] ?? '未知';
            }
        );
    }

    /**
     * 获取该支付通道下的所有子账户。
     *
     * @return HasMany
     */
    public function paymentChannelAccount(): HasMany
    {
        return $this->hasMany(PaymentChannelAccount::class);
    }
}
