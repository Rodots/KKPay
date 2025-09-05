<?php

declare(strict_types = 1);

namespace App\model;

use support\Model;

/**
 * 站点配置表
 */
class Config extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'config';

    /**
     * 与表关联的主键。
     *
     * @var string
     */
    protected $primaryKey = 'g';

    /**
     * 指示模型的 ID 是否是自动递增的。
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * 指示模型是否应被时间戳。
     *
     * @var bool
     */
    public $timestamps = false;
}
