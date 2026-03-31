<?php

declare(strict_types=1);

namespace app\merchant\controller;

use app\model\Merchant;
use app\model\MerchantSecurity;
use Core\baseController\MerchantBase;
use SodiumException;
use support\Cache;
use support\Log;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use support\Rodots\JWT\JwtToken;
use Throwable;
use Vectorface\GoogleAuthenticator;

/**
 * 商户端 - 账户管理控制器
 */
class AccountController extends MerchantBase
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['login'];

    /**
     * 商户登录接口
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

        if (empty($params['merchant_number']) || empty($params['password'])) {
            return $this->fail('请先填写商户编号/密码');
        }

        $merchantNumber = trim((string)$params['merchant_number']);
        if (!preg_match('/^M[A-Za-z0-9]{15}$/', $merchantNumber)) {
            return $this->fail('商户编号格式不正确');
        }

        $merchant = Merchant::where('merchant_number', $merchantNumber)->first(['id', 'merchant_number', 'password', 'salt', 'status', 'email', 'mobile']);
        if (empty($merchant)) {
            return $this->fail('商户编号或密码不正确');
        }

        // 检查商户状态
        if (!$merchant->status) {
            return $this->fail('该商户已被禁用，请联系管理员');
        }

        // 验证登录密码
        $hashedPassword = hash('xxh128', $params['password']) . $merchant->salt;
        if (!password_verify($hashedPassword, $merchant->password)) {
            $this->merchantLog("登录失败", $merchant->id);
            return $this->fail('商户编号或密码不正确');
        }

        // 验证TOTP双重验证代码（如果启用）
        $security = MerchantSecurity::find($merchant->id);
        if ($security && !empty($security->totp_secret) && !$this->verifyTotpCode($security->totp_secret, $params['totp_code'] ?? '')) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        // 检查密码是否需要重新哈希
        if (password_needs_rehash($merchant->password, PASSWORD_BCRYPT)) {
            $merchant->password = password_hash($hashedPassword, PASSWORD_BCRYPT);
            $merchant->save();
        }

        $ip = get_client_ip();
        try {
            Cache::set('merchant_login_ip_' . $merchant->id, $ip, 86400);
            $token = JwtToken::getInstance()->expire(config('kkpay.jwt_expire_time', 900))->generate(['merchant_id' => $merchant->id]);
        } catch (Throwable $e) {
            Log::error('商户端登录失败: ' . $e->getMessage());
            return $this->error('登录失败，请稍后再试');
        }

        // 更新最后登录时间和IP
        if ($security) {
            $security->last_login_time = time();
            $security->last_login_ip   = $ip;
            $security->save();
        }

        $this->merchantLog("登录成功", $merchant->id);

        return $this->success('登录成功', [
            'merchant_number' => $merchant->merchant_number,
            'token'           => $token,
            'avatar'          => 'https://weavatar.com/avatar/' . hash('sha256', $merchant->email ?: '2854203763@qq.com') . '?d=mp',
            'email'           => $merchant->email,
            'mobile'          => $merchant->mobile,
        ]);
    }

    /**
     * 获取当前商户用户信息
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function getProfile(Request $request): Response
    {
        $merchant = Merchant::find($request->MerchantInfo['id'], ['merchant_number', 'email', 'mobile']);
        if (empty($merchant)) {
            return $this->fail('商户信息不存在');
        }

        return $this->success('获取用户信息成功', [
            'merchant_number' => $merchant->merchant_number,
            'avatar'          => 'https://weavatar.com/avatar/' . hash('sha256', $merchant->email ?: '2854203763@qq.com') . '?d=mp',
            'email'           => $merchant->email,
            'mobile'          => $merchant->mobile,
        ]);
    }

    /**
     * 修改商户登录密码
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function passwordEdit(Request $request): Response
    {
        $password    = $request->post('password');
        $newPassword = $request->post('newPassword');
        $totpCode    = $request->post('totp_code');

        if (empty($password) || empty($newPassword)) {
            return $this->fail('原密码和新密码不能为空');
        }

        $newPassword = trim($newPassword);
        if (strlen($newPassword) < 5) {
            return $this->fail('密码长度不能小于5位');
        }

        $merchant = Merchant::find($request->MerchantInfo['id']);

        // 验证原密码
        $hashedPassword = hash('xxh128', $password) . $merchant->salt;
        if (!password_verify($hashedPassword, $merchant->password)) {
            return $this->fail('原密码错误');
        }

        // 验证TOTP双重验证代码（如果启用）
        $security = MerchantSecurity::find($merchant->id);
        if ($security && !empty($security->totp_secret) && !$this->verifyTotpCode($security->totp_secret, $totpCode ?? '')) {
            return $this->fail('TOTP一次性密码错误');
        }

        // 更新密码
        $merchant->salt     = random(4);
        $merchant->password = password_hash(hash('xxh128', $newPassword) . $merchant->salt, PASSWORD_BCRYPT);

        if ($merchant->save()) {
            $this->merchantLog("修改登录密码", $merchant->id);
            return $this->success('登录密码修改成功');
        }

        return $this->fail('密码修改失败，请重试或联系管理员');
    }

    /**
     * 修改商户基本信息
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function basicEdit(Request $request): Response
    {
        $params = $request->only(['email', 'mobile']);

        try {
            validate([
                'email'  => ['email'],
                'mobile' => ['mobile'],
            ], [
                'email.email'   => '邮箱格式不正确',
                'mobile.mobile' => '手机号格式不正确',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $merchant         = Merchant::find($request->MerchantInfo['id']);
        $merchant->email  = empty($params['email']) ? null : trim($params['email']);
        $merchant->mobile = empty($params['mobile']) ? null : trim($params['mobile']);

        if ($merchant->save()) {
            $this->merchantLog("修改基本信息", $merchant->id);
            return $this->success('基本信息修改成功', [
                'nickname' => $merchant->nickname,
                'email'    => $merchant->email,
                'mobile'   => $merchant->mobile,
                'avatar'   => 'https://weavatar.com/avatar/' . hash('sha256', $merchant->email ?: '2854203763@qq.com') . '?d=mp',
            ]);
        }

        return $this->fail('基本信息修改失败，请重试或联系管理员');
    }

    /**
     * 生成TOTP双重验证密钥
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function totpGenerate(Request $request): Response
    {
        $merchantId = $request->MerchantInfo['id'];
        $merchant   = Merchant::find($merchantId);
        $security   = MerchantSecurity::find($merchantId);

        $code = $request->post('code');
        if ($code !== null) {
            if (empty($code)) {
                return $this->fail('请输入TOTP验证码');
            }
            if (!$security || empty($security->totp_secret) || !$this->verifyTotpCode($security->totp_secret, $code)) {
                return $this->fail('TOTP验证码错误');
            }
        } elseif ($security && !empty($security->totp_secret)) {
            return $this->fail('您已启用并绑定了TOTP，请勿重复操作');
        }

        try {
            $ga     = new GoogleAuthenticator();
            $secret = $ga->createSecret();

            Cache::set('totp_verify_merchant_' . $merchantId, $secret, 300);

            return $this->success('生成TOTP密钥成功，请在5分钟内完成绑定', [
                'secret'  => $secret,
                'qr_code' => $ga->getQRCodeUrl($merchant->merchant_number, $secret, sys_config('system', 'site_name', '卡卡聚合支付')),
            ]);
        } catch (Throwable $e) {
            Log::error('商户端生成TOTP密钥失败: ' . $e->getMessage());
            return $this->fail('生成TOTP密钥失败，请重试或联系管理员');
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

        $merchantId = $request->MerchantInfo['id'];
        $cacheKey   = 'totp_verify_merchant_' . $merchantId;
        $secret     = Cache::get($cacheKey);

        if (!$secret) {
            return $this->fail('TOTP密钥已过期，请重新生成');
        }

        if (!new GoogleAuthenticator()->verifyCode($secret, $code)) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        Cache::delete($cacheKey);

        try {
            $security = MerchantSecurity::find($merchantId);
            if (!$security) {
                return $this->fail('商户安全信息不存在');
            }
            $security->totp_secret = new XChaCha20(config('kkpay.totp_crypto_key', ''))->encrypt($secret);

            if (!$security->save()) {
                return $this->fail('绑定失败，请稍后重试');
            }

            $merchant = Merchant::find($merchantId);
            $this->merchantLog("启用TOTP双重验证", $merchantId);
            return $this->success('启用成功');
        } catch (Throwable $e) {
            Log::error('商户端TOTP绑定失败: ' . $e->getMessage());
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

        $merchantId = $request->MerchantInfo['id'];
        $security   = MerchantSecurity::find($merchantId);

        if (!$security || empty($security->totp_secret)) {
            return $this->fail('您未启用TOTP，请勿重复操作');
        }

        if (!$this->verifyTotpCode($security->totp_secret, $code)) {
            return $this->fail('TOTP一次性密码不正确，请重新输入');
        }

        $security->totp_secret = null;

        if (!$security->save()) {
            return $this->fail('禁用失败，请稍后重试');
        }

        $merchant = Merchant::find($merchantId);
        $this->merchantLog("禁用TOTP双重验证", $merchantId);
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
            Log::error('商户端TOTP验证异常: ' . $e->getMessage());
            return false;
        }
    }
}
