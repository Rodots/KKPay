<?php

declare(strict_types = 1);

namespace app\model;

use support\Model;

/**
 * 管理员角色表
 */
class AdminRole extends Model
{
    /**
     * 与模型关联的表
     *
     * @var string
     */
    protected $table = 'admin_role';

    /**
     * 获取应该转换的属性。
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array'
        ];
    }
}
