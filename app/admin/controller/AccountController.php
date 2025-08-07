<?php

declare(strict_types = 1);

namespace app\admin\controller;

use app\model\Admin;
use core\baseController\AdminBase;
use support\Cache;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use Throwable;
use Vectorface\GoogleAuthenticator;

class AccountController extends AdminBase
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['login'];

    /**
     * 登录
     */
    public function login(Request $request): Response
    {
        // 1. 首先验证请求方法和参数完整性
        if (!$request->isPost()) {
            return $this->fail('非法请求');
        }

        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        // 2. 解密参数并验证必要字段
        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        if (empty($params['account']) || empty($params['password'])) {
            return $this->fail('请先填写账号/密码');
        }

        // 3. 查询用户信息
        $row = Admin::where('account', trim($params['account']))->first();
        if (empty($row)) {
            return $this->fail('账号或密码不正确');
        }

        // 4. 验证密码
        $hashedPassword = $row->salt . hash('xxh128', $params['password']) . 'kkpay';
        if (!password_verify($hashedPassword, $row->password)) {
            $this->adminLog('登录失败', $row->id);
            return $this->fail('账号或密码不正确');
        }

        // 5. 检查并更新密码哈希（如果需要）
        if (password_needs_rehash($row->password, PASSWORD_BCRYPT)) {
            $row->salt     = random(4);
            $row->password = password_hash($hashedPassword, PASSWORD_BCRYPT);
            $row->save();
        }

        // 6. TOTP验证（如果有设置）
        if (!empty($row->totp_secret) && !$this->verifyTotpCode($row->totp_secret, $params['totp_code'] ?? '')) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        try {
            // 7. 记录登录IP地址
            Cache::set('admin_login_ip_' . $row->id, get_client_ip(), 86400);

            // 8. 生成JWT令牌
            $ext   = ['admin_id' => $row->id];
            $token = \support\Rodots\JWT\JwtToken::getInstance()->generate($ext);
        } catch (Throwable $e) {
            Log::error('管理端登录失败: ' . $e->getMessage());
            return $this->error('登录失败，请稍后再试');
        }

        // 9. 记录登录日志
        $this->adminLog('登录成功', $row->id);

        // 10. 返回成功响应
        return $this->success('登录成功', [
            'account'  => $row->account,
            'nickname' => $row->nickname,
            'token'    => $token,
            'avatar'   => 'https://weavatar.com/avatar/' . hash('sha256', $row->email ?: '2854203763@qq.com') . '?d=mp',
            'email'    => $row->email,
            'rolename' => $this->getRoleName($row->role)
        ]);
    }


    /**
     * 获取用户信息
     */
    public function getProfile(Request $request): Response
    {
        return $this->success('获取用户信息成功', Admin::getProfile($request->AdminInfo['id']));
    }

    /**
     * 获取权限
     */
    public function permission(): Response
    {
        return $this->success('获取权限成功', [
            'permissions' => $this->getRolePermissions()
        ]);
    }

    /**
     * 修改密码
     */
    public function passwordEdit(Request $request): Response
    {
        // 1. 验证请求方法
        if (!$request->isPost()) {
            return $this->fail('非法请求');
        }

        // 2. 验证参数
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        // 3. 解密参数
        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        // 4. 验证必要字段
        if (empty($params['password']) || empty($params['newPassword'])) {
            return $this->fail('原密码和新密码不能为空');
        }

        $newPassword = trim($params['newPassword']);
        if (strlen($newPassword) < 5) {
            return $this->fail('密码长度不能小于5位');
        }

        // 5. 获取用户信息
        $row = Admin::where('id', $request->AdminInfo['id'])->first();

        // 6. 验证原密码
        $hashedPassword = $row->salt . hash('xxh128', $params['password']) . 'kkpay';
        if (!password_verify($hashedPassword, $row->password)) {
            return $this->fail('原密码错误');
        }

        // 7. TOTP验证（如果启用）
        if (!empty($row->totp_secret) && !$this->verifyTotpCode($row->totp_secret, $params['totp_code'] ?? '')) {
            return $this->fail('TOTP一次性密码错误');
        }

        // 8. 更新密码
        $row->salt     = random(4);
        $row->password = password_hash($row->salt . hash('xxh128', $newPassword) . 'kkpay', PASSWORD_BCRYPT);

        if ($row->save()) {
            $this->adminLog('修改密码', $row->id);
            return $this->success('密码修改成功');
        }

        return $this->fail('密码修改失败，请重试或联系运维');
    }

    /**
     * 修改基本信息
     */
    public function basicEdit(Request $request): Response
    {
        // 精简请求验证逻辑
        if (!$request->isPost()) {
            return $this->fail('非法请求');
        }

        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        // 提取参数解密逻辑
        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        try {
            validate([
                'nickname|昵称' => 'require|max:16',
                'email|邮箱'    => 'email',
            ], [
                'nickname.require' => '昵称不能为空',
                'nickname.max'     => '昵称长度不能超过16个字符',
                'email.email'      => '邮箱格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $row           = Admin::where('id', $request->AdminInfo['id'])->first();
        $row->nickname = trim($params['nickname']);
        $row->email    = empty($params['email']) ? null : trim($params['email']);
        if ($row->save()) {
            $this->adminLog('修改基本信息', $row->id);
            return $this->success('基本信息修改成功', [
                'nickname' => $row->nickname,
                'email'    => $row->email,
                'avatar'   => 'https://weavatar.com/avatar/' . hash('sha256', $row->email ?: '2854203763@qq.com') . '?d=mp'
            ]);
        }
        return $this->fail('基本信息修改失败，请重试或联系运维');
    }


    /**
     * 生成TOTP密钥
     */
    public function totpGenerate(Request $request): Response
    {
        $admin = Admin::find($request->AdminInfo['id']);

        // 处理重置密钥的验证逻辑
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
            $ga = new GoogleAuthenticator();
            $secret = $ga->createSecret();

            // 使用更清晰的Redis操作
            Redis::setex('KKPay:TOTP:Verify:admin:' . $request->AdminInfo['id'], 300, $secret);

            return $this->success('生成TOTP密钥成功，请在5分钟内完成绑定', [
                'secret' => $secret,
                'qr_code' => $ga->getQRCodeUrl(
                    $admin->account,
                    $secret,
                    '卡卡聚合支付系统'
                )
            ]);
        } catch (Throwable $e) {
            Log::error('生成TOTP密钥失败: ' . $e);
            return $this->fail('生成TOTP密钥失败，请重试或联系运维');
        }
    }

    /**
     * 验证TOTP密钥
     */
    public function totpVerify(Request $request): Response
    {
        $code = $request->post('code');
        if (!$code) {
            return $this->fail('请输入TOTP验证码');
        }

        $adminId = $request->AdminInfo['id'];
        $redisKey = 'KKPay:TOTP:Verify:admin:' . $adminId;
        $secret = Redis::get($redisKey);

        if (!$secret) {
            return $this->fail('TOTP密钥已过期，请重新生成');
        }

        // 验证TOTP代码
        if (!new GoogleAuthenticator()->verifyCode($secret, $code)) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        // 验证成功后清理Redis中的临时密钥
        Redis::del($redisKey);

        // 加密并保存TOTP密钥
        $totpSecret = new XChaCha20(config('kkpay.totp_crypto_key', ''))->encrypt($secret);
        $admin = Admin::find($adminId);
        $admin->totp_secret = $totpSecret;

        if (!$admin->save()) {
            return $this->fail('绑定失败，请稍后重试');
        }

        $this->adminLog('启用TOTP双重验证', $adminId);
        return $this->success('启用成功');
    }

    /**
     * 禁用TOTP密钥
     */
    public function totpDisable(Request $request): Response
    {
        $code = $request->post('code');
        if (!$code) {
            return $this->fail('请输入TOTP验证码');
        }

        $adminId = $request->AdminInfo['id'];
        $admin = Admin::find($adminId);

        if (empty($admin->totp_secret)) {
            return $this->fail('您未启用TOTP，请勿重复操作');
        }

        // 验证TOTP代码
        if (!$this->verifyTotpCode($admin->totp_secret, $code)) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        $admin->totp_secret = null;

        if (!$admin->save()) {
            return $this->fail('禁用失败，请稍后重试');
        }

        $this->adminLog('禁用TOTP双重验证', $adminId);
        return $this->success('禁用成功');
    }

    /**
     * 验证TOTP代码
     */
    private function verifyTotpCode(string $totpSecret, string $totpCode): bool
    {
        // 更严格的输入验证
        if (empty($totpCode) || strlen($totpCode) !== 6 || !ctype_digit($totpCode)) {
            return false;
        }

        try {
            $decryptedSecret = new XChaCha20(config('kkpay.totp_crypto_key', ''))->decrypt($totpSecret);
            return new GoogleAuthenticator()->verifyCode($decryptedSecret, $totpCode);
        } catch (Throwable $e) {
            // 记录解密或验证过程中的异常
            Log::error('TOTP验证异常: ' . $e->getMessage());
            return false;
        }
    }
}
