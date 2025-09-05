<?php

declare(strict_types = 1);

namespace App\model;

use support\Model;

/**
 * 用户/客户黑名单表
 */
class Blacklist extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'blacklist';

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
            'risk_level' => 'integer',
            'expired_at' => 'timestamp'
        ];
    }
}
