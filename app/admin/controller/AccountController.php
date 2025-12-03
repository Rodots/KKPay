<?php

declare(strict_types = 1);

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
        // 获取请求参数
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        // 解密请求参数
        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        // 验证账号密码是否为空
        if (empty($params['account']) || empty($params['password'])) {
            return $this->fail('请先填写账号/密码');
        }

        // 查询管理员信息
        $row = Admin::where('account', trim($params['account']))->first();
        if (empty($row)) {
            return $this->fail('账号或密码不正确');
        }

        // 验证密码是否正确
        $hashedPassword = $row->salt . hash('xxh128', $params['password']) . 'kkpay';
        if (!password_verify($hashedPassword, $row->password)) {
            $this->adminLog('登录失败', $row->id);
            return $this->fail('账号或密码不正确');
        }

        // 验证TOTP双重验证代码（如果启用）
        if (!empty($row->totp_secret) && !$this->verifyTotpCode($row->totp_secret, $params['totp_code'] ?? '')) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        // 检查密码是否需要重新哈希
        if (password_needs_rehash($row->password, PASSWORD_BCRYPT)) {
            $row->salt     = random(4);
            $row->password = password_hash($hashedPassword, PASSWORD_BCRYPT);
            $row->save();
        }

        try {
            // 缓存登录IP地址
            Cache::set('admin_login_ip_' . $row->id, get_client_ip(), 86400);

            // 生成JWT令牌
            $ext   = ['admin_id' => $row->id];
            $token = JwtToken::getInstance()->expire(config('kkpay.jwt_expire_time', 900))->generate($ext);
        } catch (Throwable $e) {
            // 记录登录错误日志
            Log::error('管理端登录失败: ' . $e->getMessage());
            return $this->error('登录失败，请稍后再试');
        }

        // 记录登录成功日志
        $this->adminLog('登录成功', $row->id);

        // 返回登录成功响应
        return $this->success('登录成功', [
            'account'   => $row->account,
            'nickname'  => $row->nickname,
            'token'     => $token,
            'avatar'    => 'https://weavatar.com/avatar/' . hash('sha256', $row->email ?: '2854203763@qq.com') . '?d=mp',
            'email'     => $row->email,
            'role_name' => $row->role_name
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
        // 获取角色权限并返回
        return $this->success('获取权限成功', [
            'permissions' => $this->getRolePermissions()
        ]);
    }

    /**
     * 修改管理员密码
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     * @throws SodiumException
     */
    public function passwordEdit(Request $request): Response
    {
        // 获取请求参数
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        // 解密请求参数
        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        // 验证原密码和新密码是否为空
        if (empty($params['password']) || empty($params['newPassword'])) {
            return $this->fail('原密码和新密码不能为空');
        }

        // 验证新密码长度
        $newPassword = trim($params['newPassword']);
        if (strlen($newPassword) < 5) {
            return $this->fail('密码长度不能小于5位');
        }

        // 查询管理员信息
        $row = Admin::where('id', $request->AdminInfo['id'])->first();

        // 验证原密码是否正确
        $hashedPassword = $row->salt . hash('xxh128', $params['password']) . 'kkpay';
        if (!password_verify($hashedPassword, $row->password)) {
            return $this->fail('原密码错误');
        }

        // 验证TOTP双重验证代码（如果启用）
        if (!empty($row->totp_secret) && !$this->verifyTotpCode($row->totp_secret, $params['totp_code'] ?? '')) {
            return $this->fail('TOTP一次性密码错误');
        }

        // 更新密码
        $row->salt     = random(4);
        $row->password = password_hash($row->salt . hash('xxh128', $newPassword) . 'kkpay', PASSWORD_BCRYPT);

        // 保存并返回结果
        if ($row->save()) {
            $this->adminLog('修改密码', $row->id);
            return $this->success('密码修改成功');
        }

        return $this->fail('密码修改失败，请重试或联系运维');
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
        // 获取请求参数
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        // 解密请求参数
        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        try {
            // 验证参数合法性
            validate([
                'nickname' => ['require', 'max:16'],
                'email'    => ['email'],
            ], [
                'nickname.require' => '昵称不能为空',
                'nickname.max'     => '昵称长度不能超过16个字符',
                'email.email'      => '邮箱格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 更新管理员信息
        $row           = Admin::find($request->AdminInfo['id']);
        $row->nickname = trim($params['nickname']);
        $row->email    = empty($params['email']) ? null : trim($params['email']);

        // 保存并返回结果
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
     * 生成TOTP双重验证密钥
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function totpGenerate(Request $request): Response
    {
        // 获取管理员信息
        $admin = Admin::find($request->AdminInfo['id']);

        // 获取验证码参数
        $code = $request->post('code');
        if ($code !== null) {
            // 验证验证码是否为空
            if (empty($code)) {
                return $this->fail('请输入TOTP验证码');
            }

            // 验证TOTP验证码是否正确
            if (!$this->verifyTotpCode($admin->totp_secret, $code)) {
                return $this->fail('TOTP验证码错误');
            }
        } elseif (!empty($admin->totp_secret)) {
            // 检查是否已绑定TOTP
            return $this->fail('您已启用并绑定了TOTP，请勿重复操作');
        }

        try {
            // 创建Google验证器实例
            $ga     = new GoogleAuthenticator();
            $secret = $ga->createSecret();

            // 将密钥存储到Redis中，有效期5分钟
            Redis::setex('TOTP:Verify:admin:' . $request->AdminInfo['id'], 300, $secret);

            // 返回生成的密钥和二维码
            return $this->success('生成TOTP密钥成功，请在5分钟内完成绑定', [
                'secret'  => $secret,
                'qr_code' => $ga->getQRCodeUrl(
                    $admin->account,
                    $secret,
                    '卡卡聚合支付系统'
                )
            ]);
        } catch (Throwable $e) {
            // 记录错误日志并返回失败响应
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
        // 获取验证码参数
        $code = $request->post('code');
        if (!$code) {
            return $this->fail('请输入TOTP验证码');
        }

        // 获取管理员ID和Redis键
        $adminId  = $request->AdminInfo['id'];
        $redisKey = 'TOTP:Verify:admin:' . $adminId;
        $secret   = Redis::get($redisKey);

        // 检查密钥是否过期
        if (!$secret) {
            return $this->fail('TOTP密钥已过期，请重新生成');
        }

        // 验证TOTP验证码是否正确
        if (!new GoogleAuthenticator()->verifyCode($secret, $code)) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        // 删除Redis中的临时密钥
        Redis::del($redisKey);

        try {
            // 加密并保存TOTP密钥
            $totpSecret         = new XChaCha20(config('kkpay.totp_crypto_key', ''))->encrypt($secret);
            $admin              = Admin::find($adminId);
            $admin->totp_secret = $totpSecret;

            // 保存并返回结果
            if (!$admin->save()) {
                return $this->fail('绑定失败，请稍后重试');
            }

            // 记录日志并返回成功响应
            $this->adminLog('启用TOTP双重验证', $adminId);
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
        // 获取验证码参数
        $code = $request->post('code');
        if (!$code) {
            return $this->fail('请输入TOTP验证码');
        }

        // 获取管理员信息
        $adminId = $request->AdminInfo['id'];
        $admin   = Admin::find($adminId);

        // 检查是否已启用TOTP
        if (empty($admin->totp_secret)) {
            return $this->fail('您未启用TOTP，请勿重复操作');
        }

        // 验证TOTP验证码是否正确
        if (!$this->verifyTotpCode($admin->totp_secret, $code)) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        // 清除TOTP密钥
        $admin->totp_secret = null;

        // 保存并返回结果
        if (!$admin->save()) {
            return $this->fail('禁用失败，请稍后重试');
        }

        // 记录日志并返回成功响应
        $this->adminLog('禁用TOTP双重验证', $adminId);
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
        // 验证验证码格式
        if (empty($totpCode) || strlen($totpCode) !== 6 || !ctype_digit($totpCode)) {
            return false;
        }

        try {
            // 解密TOTP密钥
            $decryptedSecret = new XChaCha20(config('kkpay.totp_crypto_key', ''))->decrypt($totpSecret);
            // 验证TOTP验证码
            return new GoogleAuthenticator()->verifyCode($decryptedSecret, $totpCode);
        } catch (Throwable $e) {
            // 记录验证异常日志
            Log::error('TOTP验证异常: ' . $e->getMessage());
            return false;
        }
    }
}
