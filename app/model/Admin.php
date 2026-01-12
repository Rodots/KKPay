<?php

declare(strict_types=1);

namespace app\model;

use Carbon\Carbon;
use Core\Traits\AdminRole;
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
            'role'   => 'integer',
            'status' => 'boolean'
        ];
    }

    /**
     * 可批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = [
        'status',
    ];

    /**
     * 序列化时应隐藏的属性。
     *
     * @var array<string>
     */
    protected $hidden = ['login_password', 'fund_password', 'login_salt', 'fund_salt', 'totp_secret'];

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
            $row           = new self();
            $row->role     = $data['role'];
            $row->account  = trim($data['account']);
            $row->nickname = trim($data['nickname']);
            $row->email    = empty($data['email']) ? null : trim($data['email']);
            $row->status   = (int)$data['status'];

            // 登录密码
            $row->login_salt     = random(4);
            $row->login_password = password_hash('login' . $row->login_salt . hash('xxh128', trim($data['password'])) . 'kkpay', PASSWORD_BCRYPT);

            // 资金密码（默认123456）
            $row->fund_salt     = random(4);
            $row->fund_password = password_hash('fund' . $row->fund_salt . '4c2b6eecc66547d595102682557afd52kkpay', PASSWORD_BCRYPT);

            $row->save();
        } catch (Throwable $e) {
            Log::error('创建管理员失败: ' . $e->getMessage());
            throw new Exception('创建失败');
        }

        return $row;
    }

    /**
     * 更新管理员
     *
     * @param int   $id   管理员ID
     * @param array $data 数据
     * @return true 更新是否成功
     * @throws Exception
     */
    public static function updateAdmin(int $id, array $data): true
    {
        $row = self::find($id);
        if (!$row) {
            throw new Exception('该管理员不存在');
        }

        $account = trim($data['account']);
        if ($row->account !== $account && self::where('account', $account)->exists()) {
            throw new Exception('该账号/用户名已存在');
        }

        try {
            $row->role     = $data['role'];
            $row->account  = $account;
            $row->nickname = trim($data['nickname']);
            $row->email    = empty($data['email']) ? null : trim($data['email']);
            $row->status   = (int)$data['status'];

            // 更新登录密码
            if (!empty($data['new_password'])) {
                $row->login_salt     = random(4);
                $row->login_password = password_hash('login' . $row->login_salt . hash('xxh128', trim($data['new_password'])) . 'kkpay', PASSWORD_BCRYPT);
            }

            $row->save();
        } catch (Throwable $e) {
            Log::error('编辑管理员失败: ' . $e->getMessage());
            throw new Exception('编辑失败');
        }

        return true;
    }

    /**
     * 重置管理员密码为123456
     *
     * @param int    $id   管理员ID
     * @param string $type 密码类型（login 或 fund）
     * @return true 重置是否成功
     * @throws Exception
     */
    public static function resetPassword(int $id, string $type = 'login'): true
    {
        $row = self::find($id);
        if (!$row) {
            throw new Exception('该管理员不存在');
        }

        // 根据类型重置对应密码
        $saltField     = $type . '_salt';
        $passwordField = $type . '_password';

        $row->$saltField     = random(4);
        $row->$passwordField = password_hash($type . $row->$saltField . '4c2b6eecc66547d595102682557afd52kkpay', PASSWORD_BCRYPT);

        if (!$row->save()) {
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
        $row = self::find($id);
        if (!$row) {
            throw new Exception('该管理员不存在');
        }

        $row->totp_secret = null;

        if (!$row->save()) {
            throw new Exception('重置TOTP失败');
        }

        return true;
    }
}
