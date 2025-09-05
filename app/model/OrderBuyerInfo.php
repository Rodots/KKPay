<?php

declare(strict_types = 1);

namespace App\model;

use support\Model;

/**
 * 订单买家信息表
 */
class OrderBuyerInfo extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'order_buyer_info';

    /**
     * 与表关联的主键。
     *
     * @var string
     */
    protected $primaryKey = 'trade_no';

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
}
