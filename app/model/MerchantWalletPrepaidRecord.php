<?php

declare(strict_types = 1);

namespace app\model;

use support\Model;

/**
 * 商户钱包预付金变动记录表
 */
class MerchantWalletPrepaidRecord extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_wallet_prepaid_record';

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
            'merchant_id' => 'integer',
            'old_balance' => 'decimal:2',
            'amount' => 'decimal:2',
            'new_balance' => 'decimal:2'
        ];
    }
}
