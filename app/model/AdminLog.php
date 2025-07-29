<?php

declare(strict_types = 1);

namespace app\model;

use support\Model;

/**
 * 管理员操作日志表
 */
class AdminLog extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'admin_log';

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
            'admin_id' => 'integer'
        ];
    }
}
