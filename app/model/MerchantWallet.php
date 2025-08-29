<?php

declare(strict_types = 1);

namespace app\model;

use support\Model;

/**
 * 商户钱包表
 */
class MerchantWallet extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_wallet';

    /**
     * 与表关联的主键。
     *
     * @var string
     */
    protected $primaryKey = 'merchant_id';

    /**
     * 指示模型的 ID 是否是自动递增的。
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * 获取应该转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'balance'        => 'decimal:2',
            'freeze_balance' => 'decimal:2',
            'margin'         => 'decimal:2',
            'prepaid'        => 'decimal:2'
        ];
    }
}
