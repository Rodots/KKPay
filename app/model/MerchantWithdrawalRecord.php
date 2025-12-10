<?php

declare(strict_types = 1);

namespace app\model;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
    protected $table = 'merchant_wallet_record';

    /**
     * 获取应转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'merchant_id'     => 'integer',
            'payee_info'      => 'array',
            'amount'          => 'decimal:2',
            'received_amount' => 'decimal:2',
            'fee'             => 'decimal:2',
            'fee_type'        => 'boolean'
        ];
    }

    // 提现状态枚举
    const string STATUS_PENDING    = 'PENDING';
    const string STATUS_PROCESSING = 'PROCESSING';
    const string STATUS_COMPLETED  = 'COMPLETED';
    const string STATUS_FAILED     = 'FAILED';
    const string STATUS_REJECTED   = 'REJECTED';
    const string STATUS_CANCELED   = 'CANCELED';

    /***
     * 访问器【状态文本】
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
                    self::STATUS_FAILED     => '提现失败',
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
    protected function FeeTypeText(): Attribute
    {
        return Attribute::make(
            get: function () {
                $enum = [
                    0 => '在提现金额内扣除',
                    1 => '在可用余额额外扣除',
                ];
                return $enum[$this->getOriginal('fee_type')] ?? '未知';
            }
        );
    }
}
