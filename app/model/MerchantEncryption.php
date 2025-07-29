<?php

declare(strict_types = 1);

namespace app\model;

use support\Model;

/**
 * 商户密钥表
 */
class MerchantEncryption extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'merchant_encryption';

    /**
     * 与表关联的主键。
     *
     * @var string
     */
    protected $primaryKey = 'merchant_id';

    /**
     * 指示模型是否应被时间戳。
     *
     * @var bool
     */
    public $timestamps = false;
}
