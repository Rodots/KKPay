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

    public static function getProfile(int $id)
    {
        $row = self::select(['role', 'account', 'nickname', 'email', 'totp_secret'])->find($id)->toArray();
        $row['totp_state'] = !empty($row['totp_secret']);
        $row['avatar'] = 'https://weavatar.com/avatar/' . hash('sha256', $row['email'] ?: '2854203763@qq.com') . '?d=mp';
        unset($row['totp_secret']);
        return $row;
    }
}
