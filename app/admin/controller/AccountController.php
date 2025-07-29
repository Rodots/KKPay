<?php

declare(strict_types = 1);

namespace app\admin\controller;

use app\model\Admin;
use core\baseController\AdminBase;
use support\Cache;
use support\Log;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use Vectorface\GoogleAuthenticator;

class AccountController extends AdminBase
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['login'];

    public function login(Request $request): Response
    {
        // 禁止非POST请求
        if ($request->isPost() === false) {
            return $this->fail('非法请求');
        }

        // 获取加密传参
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }
        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        if (empty($params['account']) || empty($params['password'])) {
            return $this->fail('请先填写账号/密码');
        }

        $row = Admin::where('account', trim($params['account']))->first();

        if (empty($row)) {
            return $this->fail('账号或密码不正确');
        }

        // 验证密码
        if (!password_verify(hash('xxh3', $row->salt . $params['password'] . 'kkpay'), $row->password)) {
            // 记录登录日志
            $this->adminLog('登录失败', $row->id);
            return $this->fail('账号或密码不正确');
        }

        // 检查是否需要更新密码哈希（哈希会随着PHP版本更新而迭代算法）
        if (password_needs_rehash($row->password, PASSWORD_DEFAULT)) {
            $row->salt = random(4);
            $row->password = password_hash(hash('xxh3', $row->salt . $params['password'] . 'kkpay'), PASSWORD_DEFAULT);
            $row->save();
        }

        if (!empty($row->totp_secret)) {
            if (empty($params['totp_code'])) {
                return $this->fail('请先填写TOTP一次性密码');
            }
            $totpCheckResult = new GoogleAuthenticator()->verifyCode($row->totp_secret, $params['totp_code']);
            if (!$totpCheckResult) {
                return $this->fail('TOTP一次性密码错误');
            }
        }

        try {
            // 记录登录IP地址，以便后续验证，登录态有效期1天
            Cache::set('admin_login_ip_' . $row->id, get_client_ip(), 86400);

            // 下发JWT令牌
            $ext = [
                'admin_id' => $row->id,
            ];
            $token = \support\Rodots\JWT\JwtToken::getInstance()->generate($ext);
        } catch (\Throwable $e) {
            Log::error('管理端登录失败：' . $e->getMessage());
            return $this->error('登录失败，请稍后再试');
        }

        // 记录登录日志
        $this->adminLog('登录成功', $row->id);

        return $this->success('登录成功', [
            'nickname' => $row->nickname ?: '无名氏',
            'token' => $token,
            'avatar' => 'https://weavatar.com/avatar/' . hash('sha256', $row->email ?: '2854203763@qq.com') . '?d=mp',
            'email' => $row->email ?: '电子邮箱未设定'
        ]);
    }

    public function permission(): Response
    {
        $permissions = [
            'permission.browse',
            'permission.create',
            'permission.edit',
            'permission.remove',
        ];
        return $this->success('获取权限成功', ['permissions' => $permissions]);
    }
}
