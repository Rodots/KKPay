<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Admin;
use Core\baseController\AdminBase;
use SodiumException;
use support\Cache;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use support\Rodots\JWT\JwtToken;
use Throwable;
use Vectorface\GoogleAuthenticator;

class AccountController extends AdminBase
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['login'];

    /**
     * 管理员登录接口
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     * @throws SodiumException
     */
    public function login(Request $request): Response
    {
        $params = $this->decryptPayload($request);
        if ($params === null) {
            return $this->fail('非法请求');
        }

        if (empty($params['account']) || empty($params['password'])) {
            return $this->fail('请先填写账号/密码');
        }

        $admin = Admin::where('account', trim($params['account']))->first();
        if (empty($admin)) {
            return $this->fail('账号或密码不正确');
        }

        // 验证登录密码
        $hashedPassword = 'login' . $admin->login_salt . hash('xxh128', $params['password']) . 'kkpay';
        if (!password_verify($hashedPassword, $admin->login_password)) {
            $this->adminLog("管理员【{$admin->account}】登录失败", $admin->id);
            return $this->fail('账号或密码不正确');
        }

        // 验证TOTP双重验证代码（如果启用）
        if (!empty($admin->totp_secret) && !$this->verifyTotpCode($admin->totp_secret, $params['totp_code'] ?? '')) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        // 检查密码是否需要重新哈希
        if (password_needs_rehash($admin->login_password, PASSWORD_BCRYPT)) {
            $admin->login_password = password_hash($hashedPassword, PASSWORD_BCRYPT);
            $admin->save();
        }

        try {
            Cache::set('admin_login_ip_' . $admin->id, get_client_ip(), 86400);
            $token = JwtToken::getInstance()
                ->expire(config('kkpay.jwt_expire_time', 900))
                ->generate(['admin_id' => $admin->id]);
        } catch (Throwable $e) {
            Log::error('管理端登录失败: ' . $e->getMessage());
            return $this->error('登录失败，请稍后再试');
        }

        $this->adminLog("管理员【{$admin->account}】登录成功", $admin->id);

        return $this->success('登录成功', [
            'account'   => $admin->account,
            'nickname'  => $admin->nickname,
            'token'     => $token,
            'avatar'    => 'https://weavatar.com/avatar/' . hash('sha256', $admin->email ?: '2854203763@qq.com') . '?d=mp',
            'email'     => $admin->email,
            'role_name' => $admin->role_name
        ]);
    }

    /**
     * 获取当前管理员用户信息
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function getProfile(Request $request): Response
    {
        return $this->success('获取用户信息成功', Admin::getProfile($request->AdminInfo['id']));
    }

    /**
     * 获取当前管理员权限列表
     *
     * @return Response 响应对象
     */
    public function permission(): Response
    {
        return $this->success('获取权限成功', [
            'permissions' => $this->getRolePermissions()
        ]);
    }

    /**
     * 修改管理员密码（登录密码或资金密码）
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     * @throws SodiumException
     */
    public function passwordEdit(Request $request): Response
    {
        $params = $this->decryptPayload($request);
        if ($params === null) {
            return $this->fail('非法请求');
        }

        // 验证密码类型
        $type = $params['type'] ?? '';
        if (!in_array($type, ['login', 'fund'], true)) {
            return $this->fail('密码类型不正确');
        }

        if (empty($params['password']) || empty($params['newPassword'])) {
            return $this->fail('原密码和新密码不能为空');
        }

        $newPassword = trim($params['newPassword']);
        if (strlen($newPassword) < 5) {
            return $this->fail('密码长度不能小于5位');
        }

        $admin = Admin::find($request->AdminInfo['id']);

        // 根据类型确定字段名
        $saltField     = $type . '_salt';
        $passwordField = $type . '_password';
        $typeName      = $type === 'login' ? '登录' : '资金';

        // 验证原密码
        $hashedPassword = $type . $admin->$saltField . hash('xxh128', $params['password']) . 'kkpay';
        if (!password_verify($hashedPassword, $admin->$passwordField)) {
            return $this->fail('原密码错误');
        }

        // 验证TOTP双重验证代码（如果启用）
        if (!empty($admin->totp_secret) && !$this->verifyTotpCode($admin->totp_secret, $params['totp_code'] ?? '')) {
            return $this->fail('TOTP一次性密码错误');
        }

        // 更新密码
        $admin->$saltField     = random(4);
        $admin->$passwordField = password_hash($type . $admin->$saltField . hash('xxh128', $newPassword) . 'kkpay', PASSWORD_BCRYPT);

        if ($admin->save()) {
            $this->adminLog("修改管理员【{$admin->account}】{$typeName}密码", $admin->id);
            return $this->success("{$typeName}密码修改成功");
        }

        return $this->fail("{$typeName}密码修改失败，请重试或联系运维");
    }

    /**
     * 修改管理员基本信息
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     * @throws SodiumException
     */
    public function basicEdit(Request $request): Response
    {
        $params = $this->decryptPayload($request);
        if ($params === null) {
            return $this->fail('非法请求');
        }

        try {
            validate([
                'nickname' => ['require', 'max:16'],
                'email'    => ['email'],
            ], [
                'nickname.require' => '请输入昵称',
                'nickname.max'     => '昵称不能超过16个字',
                'email.email'      => '邮箱格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $admin           = Admin::find($request->AdminInfo['id']);
        $admin->nickname = trim($params['nickname']);
        $admin->email    = empty($params['email']) ? null : trim($params['email']);

        if ($admin->save()) {
            $this->adminLog("修改管理员【{$admin->account}】基本信息", $admin->id);
            return $this->success('基本信息修改成功', [
                'nickname' => $admin->nickname,
                'email'    => $admin->email,
                'avatar'   => 'https://weavatar.com/avatar/' . hash('sha256', $admin->email ?: '2854203763@qq.com') . '?d=mp'
            ]);
        }

        return $this->fail('基本信息修改失败，请重试或联系运维');
    }

    /**
     * 生成TOTP双重验证密钥
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function totpGenerate(Request $request): Response
    {
        $adminId = $request->AdminInfo['id'];
        $admin   = Admin::find($adminId);

        $code = $request->post('code');
        if ($code !== null) {
            if (empty($code)) {
                return $this->fail('请输入TOTP验证码');
            }
            if (!$this->verifyTotpCode($admin->totp_secret, $code)) {
                return $this->fail('TOTP验证码错误');
            }
        } elseif (!empty($admin->totp_secret)) {
            return $this->fail('您已启用并绑定了TOTP，请勿重复操作');
        }

        try {
            $ga     = new GoogleAuthenticator();
            $secret = $ga->createSecret();

            Redis::setex('TOTP:Verify:admin:' . $adminId, 300, $secret);

            return $this->success('生成TOTP密钥成功，请在5分钟内完成绑定', [
                'secret'  => $secret,
                'qr_code' => $ga->getQRCodeUrl($admin->account, $secret, sys_config('system', 'site_name', '卡卡聚合支付'))
            ]);
        } catch (Throwable $e) {
            Log::error('生成TOTP密钥失败: ' . $e->getMessage());
            return $this->fail('生成TOTP密钥失败，请重试或联系运维');
        }
    }

    /**
     * 验证并绑定TOTP密钥
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function totpVerify(Request $request): Response
    {
        $code = $request->post('code');
        if (empty($code)) {
            return $this->fail('请输入TOTP验证码');
        }

        $adminId  = $request->AdminInfo['id'];
        $redisKey = 'TOTP:Verify:admin:' . $adminId;
        $secret   = Redis::get($redisKey);

        if (!$secret) {
            return $this->fail('TOTP密钥已过期，请重新生成');
        }

        if (!new GoogleAuthenticator()->verifyCode($secret, $code)) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        Redis::del($redisKey);

        try {
            $admin              = Admin::find($adminId);
            $admin->totp_secret = new XChaCha20(config('kkpay.totp_crypto_key', ''))->encrypt($secret);

            if (!$admin->save()) {
                return $this->fail('绑定失败，请稍后重试');
            }

            $this->adminLog("管理员【{$admin->account}】启用TOTP双重验证", $adminId);
            return $this->success('启用成功');
        } catch (Throwable $e) {
            Log::error('TOTP绑定失败: ' . $e->getMessage());
            return $this->fail('绑定失败，请稍后重试');
        }
    }

    /**
     * 禁用TOTP双重验证
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function totpDisable(Request $request): Response
    {
        $code = $request->post('code');
        if (empty($code)) {
            return $this->fail('请输入TOTP验证码');
        }

        $adminId = $request->AdminInfo['id'];
        $admin   = Admin::find($adminId);

        if (empty($admin->totp_secret)) {
            return $this->fail('您未启用TOTP，请勿重复操作');
        }

        if (!$this->verifyTotpCode($admin->totp_secret, $code)) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        $admin->totp_secret = null;

        if (!$admin->save()) {
            return $this->fail('禁用失败，请稍后重试');
        }

        $this->adminLog("管理员【{$admin->account}】禁用TOTP双重验证", $adminId);
        return $this->success('禁用成功');
    }

    /**
     * 验证TOTP代码
     *
     * @param string $totpSecret TOTP密钥（加密存储）
     * @param string $totpCode   用户输入的TOTP验证码
     * @return bool 验证是否成功
     */
    private function verifyTotpCode(string $totpSecret, string $totpCode): bool
    {
        if (empty($totpCode) || strlen($totpCode) !== 6 || !ctype_digit($totpCode)) {
            return false;
        }

        try {
            $decryptedSecret = new XChaCha20(config('kkpay.totp_crypto_key', ''))->decrypt($totpSecret);
            return new GoogleAuthenticator()->verifyCode($decryptedSecret, $totpCode);
        } catch (Throwable $e) {
            Log::error('TOTP验证异常: ' . $e->getMessage());
            return false;
        }
    }
}
