<?php

declare(strict_types = 1);

namespace app\model;

use support\Model;

/**
 * 管理员表
 */
class Admin extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'admin';

    /**
     * 获取应该转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'status'  => 'boolean'
        ];
    }
}
