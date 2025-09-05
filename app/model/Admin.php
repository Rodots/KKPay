<?php

declare(strict_types = 1);

namespace App\model;

use Carbon\Carbon;
use Core\traits\AdminRole;
use Exception;
use Illuminate\Database\Eloquent\Casts\Attribute;
use support\Log;
use support\Model;
use Throwable;

/**
 * 管理员表
 */
class Admin extends Model
{
    use AdminRole;

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

    /**
     * 序列化时应隐藏的属性。
     *
     * @var array<string>
     */
    protected $hidden = ['totp_secret'];

    /**
     * 访问器：创建时间
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：更新时间
     */
    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value) => $value ? Carbon::parse($value)->timezone(config('app.default_timezone'))->format('Y-m-d H:i:s') : null,
        );
    }

    /**
     * 访问器：角色名称
     */
    protected function roleName(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->getRoleName($this->role),
        );
    }

    /**
     * 访问器：是否设置了TOTP
     */
    protected function totpState(): Attribute
    {
        return Attribute::make(
            get: fn() => (bool)$this->totp_secret,
        );
    }

    /**
     * 访问器：头像图片链接
     */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn() => 'https://weavatar.com/avatar/' . hash('sha256', $this->email ?: '2854203763@qq.com') . '?d=mp',
        );
    }

    public static function getProfile(int $id)
    {
        return self::select(['role', 'account', 'nickname', 'email', 'totp_secret'])->find($id)->append(['role_name', 'totp_state', 'avatar'])->toArray();
    }

    /**
     * 创建管理员
     *
     * @param array $data 数据
     * @return Admin 成功返回模型对象，失败抛出错误
     * @throws Exception
     */
    public static function createAdmin(array $data): Admin
    {
        if (self::where('account', $data['account'])->exists()) {
            throw new Exception('该账号/用户名已存在');
        }
        try {
            $adminRow           = new self();
            $adminRow->role     = (int)$data['role'];
            $adminRow->account  = trim($data['account']);
            $adminRow->nickname = trim($data['nickname']);
            $adminRow->email    = empty($data['email']) ? null : trim($data['email']);
            $adminRow->salt     = random(4);
            $adminRow->password = password_hash($adminRow->salt . hash('xxh128', trim($data['password'])) . 'kkpay', PASSWORD_BCRYPT);
            $adminRow->status   = (int)$data['status'];
            $adminRow->save();
        } catch (Throwable $e) {
            Log::error('创建管理员失败: ' . $e->getMessage());
            throw new Exception('创建失败');
        }

        return $adminRow;
    }

    /**
     * 更新管理员
     *
     * @param int   $id   商户ID
     * @param array $data 数据
     * @return true 更新是否成功
     * @throws Exception
     */
    public static function updateAdmin(int $id, array $data): true
    {
        $admin = self::find($id);
        if (!$admin) {
            throw new Exception('该管理员不存在');
        }
        $account = trim($data['account']);

        if ($admin->account !== $account && self::where('account', $account)->exists()) {
            throw new Exception('该账号/用户名已存在');
        }
        try {
            $admin->role = (int)$data['role'];
            if ($admin->account !== $account) {
                $admin->account = $account;
            }
            $admin->nickname = trim($data['nickname']);
            $admin->email    = empty($data['email']) ? null : trim($data['email']);
            $admin->status   = (int)$data['status'];

            // 更新密码
            if (isset($data['new_password'])) {
                $admin->salt     = random(4);
                $admin->password = password_hash($admin->salt . hash('xxh128', trim($data['new_password'])) . 'kkpay', PASSWORD_BCRYPT);
            }

            $admin->save();
        } catch (Throwable $e) {
            Log::error('编辑管理员失败: ' . $e->getMessage());
            throw new Exception('编辑失败');
        }

        return true;
    }

    /**
     * 重置管理员密码为123456
     *
     * @param int $id 管理员ID
     * @return true 重置是否成功
     * @throws Exception
     */
    public static function resetPassword(int $id): true
    {
        $admin = self::find($id);
        if (!$admin) {
            throw new Exception('该管理员不存在');
        }

        // 更新商户密码
        $admin->salt     = random(4);
        $admin->password = password_hash($admin->salt . hash('xxh128', '123456') . 'kkpay', PASSWORD_BCRYPT);

        if (!$admin->save()) {
            throw new Exception('重置密码失败');
        }
        return true;
    }

    /**
     * 重置TOTP
     *
     * @param int $id 管理员ID
     * @return true 重置是否成功
     * @throws Exception
     */
    public static function resetTotp(int $id): true
    {
        $admin = self::find($id);
        if (!$admin) {
            throw new Exception('该管理员不存在');
        }

        $admin->totp_secret = null;

        if (!$admin->save()) {
            throw new Exception('重置TOTP失败');
        }
        return true;
    }
}
