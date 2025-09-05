<?php

declare(strict_types = 1);

namespace Core\traits;

use app\model\Admin;

trait AdminRole
{
    // 超级管理员
    const int SUPER_ADMIN = 0;
    // 普通管理员
    const int NORMAL_ADMIN = 1;
    // 客服
    const int SERVICE = 2;
    // 默认角色（牛马）
    const int DEFAULT = 255;

    private array $roleHierarchy = [
        self::SUPER_ADMIN  => [
            'name'        => '超级管理员',
            'permissions' => [
                'super_admin'
            ]
        ],
        self::NORMAL_ADMIN => [
            'name'        => '普通管理员',
            'permissions' => [
                'admin'
            ]
        ],
        self::SERVICE      => [
            'name'        => '客服',
            'permissions' => [
                'service'
            ]
        ],
        self::DEFAULT      => [
            'name'        => '牛马',
            'permissions' => []
        ]
    ];

    /**
     * 缓存角色值，避免重复查询数据库
     * @var int|null
     */
    private ?int $cachedRole = null;

    /**
     * 获取当前登录的角色
     *
     * @return int
     */
    protected function getRole(): int
    {
        if ($this->cachedRole !== null) {
            return $this->cachedRole;
        }

        $this->cachedRole = Admin::where('id', request()->AdminInfo['id'])->value('role') ?? self::DEFAULT;
        return $this->cachedRole;
    }

    /**
     * 获取角色名称（可用于显示）
     *
     * @param int|null $roleId 可选的角色值，若为 null 则使用当前用户的角色
     *
     * @return string 角色名称
     */
    public function getRoleName(?int $roleId = null): string
    {
        if ($roleId === null) {
            $roleId = $this->getRole();
        }

        return $this->roleHierarchy[$roleId]['name'] ?? $this->roleHierarchy[self::DEFAULT]['name'];
    }

    /**
     * 获取角色对应拥有的权限节点
     *
     * @return array
     */
    public function getRolePermissions(): array
    {
        // 只查询一次数据库，获取当前用户角色
        $role = $this->getRole();

        // 根据角色返回对应的权限列表
        return $this->roleHierarchy[$role]['permissions'] ?? $this->roleHierarchy[self::DEFAULT]['permissions'];
    }

    /**
     * 判断是否为超级管理员
     *
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        return $this->getRole() === self::SUPER_ADMIN;
    }

    /**
     * 判断是否为普通管理员
     *
     * @return bool
     */
    public function isNormalAdmin(): bool
    {
        return $this->getRole() === self::NORMAL_ADMIN;
    }

    /**
     * 判断是否为客服
     *
     * @return bool
     */
    public function isService(): bool
    {
        return $this->getRole() === self::SERVICE;
    }

    /**
     * 检查是否有足够权限操作指定角色
     *
     * @param int $targetRole 目标角色
     * @return bool
     */
    public function canManageRole(int $targetRole): bool
    {
        return $this->getRole() <= $targetRole;
    }
}
