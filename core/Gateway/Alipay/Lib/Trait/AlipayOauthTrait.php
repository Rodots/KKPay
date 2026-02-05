<?php

declare(strict_types=1);

namespace Core\Gateway\Alipay\Lib\Trait;

use app\model\OrderBuyer;
use app\model\PaymentChannelAccount;
use Core\Gateway\Alipay\Lib\Factory;
use Core\Service\RiskService;
use Throwable;

/**
 * 支付宝用户授权及风控校验 Trait
 *
 * 提供支付宝用户授权认证和风控检查的公共方法，可被多个网关复用：
 * - Alipay 网关：支持当前渠道或公共账户模式（由配置决定）
 * - EPay/BaiExcellent 等第三方网关：强制使用公共授权账户模式
 */
trait AlipayOauthTrait
{
    /** @var array 空用户信息结构（避免重复创建） */
    private const array EMPTY_USER_DATA = ['user_id' => null, 'open_id' => null, 'mobile' => null];

    /** @var array 系统异常错误响应 */
    private const array ERROR_SYSTEM = ['type' => 'error', 'message' => '系统异常，无法完成付款'];

    /**
     * 处理支付宝用户授权及风控检查
     *
     * @param array $channel 支付渠道配置（空数组时强制使用公共账户）
     * @param array $order   订单信息，必须包含 trade_no 和 merchant_id
     * @return array ['mode' => 'continue'|'return', 'data' => array]
     */
    protected static function handleAlipayOauthAndRisk(array $channel, array $order): array
    {
        $oauth = self::alipayOauth($channel);

        // 授权失败或未配置
        if (!$oauth) {
            return ['mode' => 'continue', 'data' => self::EMPTY_USER_DATA];
        }

        // 需要重定向到授权页面或返回错误
        if ($oauth['mode'] === 'return') {
            return $oauth;
        }

        // 授权成功，提取用户信息
        $userId = $oauth['data']['user_id'];
        $openId = $oauth['data']['buyer_open_id'];
        $mobile = $oauth['data']['mobile'];

        // 批量构建更新数据（仅包含非 null 值）
        $updateData = [];
        if ($userId !== null) $updateData['user_id'] = $userId;
        if ($openId !== null) $updateData['buyer_open_id'] = $openId;
        if ($mobile !== null) $updateData['mobile'] = $mobile;

        // 更新买家信息
        if ($updateData) {
            OrderBuyer::where('trade_no', $order['trade_no'])->update($updateData);
        }

        // 风控黑名单检查（用户ID + 手机号）
        if (
            RiskService::checkUserIdBlacklist($order['merchant_id'], $userId, $openId, $order['trade_no'])
            || ($mobile && RiskService::checkMobileBlacklist($mobile, $order['merchant_id'], $order['trade_no']))
        ) {
            return ['mode' => 'return', 'data' => self::ERROR_SYSTEM];
        }

        // 返回用户信息
        return ['mode' => 'continue', 'data' => ['user_id' => $userId, 'open_id' => $openId, 'mobile' => $mobile]];
    }

    /**
     * 支付宝用户授权认证
     *
     * @param array $channel 支付渠道配置（空数组时强制使用公共账户）
     * @return array|false 授权结果或失败
     */
    protected static function alipayOauth(array $channel = []): array|false
    {
        try {
            $paymentConfig = sys_config('payment');
            $scope         = $paymentConfig['alipay_get_user_info_scope'] ?? 'auth_base';

            // 解析渠道配置（内联 resolveOauthChannel）
            if (empty($channel) || ($paymentConfig['alipay_get_user_info_mode'] ?? 'current') === 'common') {
                $row = PaymentChannelAccount::find($paymentConfig['alipay_get_user_info_common_account'] ?? 0, ['config']);
                if (!$row) return false;
                $channel = $row->config;
            }

            $appId = $channel['app_id'] ?? '';
            if (!$appId) return false;

            $request  = request();
            $authCode = $request->get('auth_code');
            $secret   = $channel['app_private_key'] . $appId;

            // 无授权码：生成授权 URL 并重定向
            if (!$authCode) {
                $ts          = time();
                $state       = $ts . '.' . substr(hash('xxh128', $secret . $ts), 12, 8);
                $redirectUri = urlencode((is_https() ? 'https:' : 'http:') . $request->fullUrl());
                $authUrl     = "https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id=$appId&scope=$scope&state=$state&redirect_uri=$redirectUri";

                return [
                    'mode' => 'return',
                    'data' => isAlipay() ? ['type' => 'location', 'url' => $authUrl] : ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => $authUrl]]
                ];
            }

            // 校验 state（CSRF 防护）
            $stateParts = explode('.', $request->get('state', '0.'), 2);
            if (count($stateParts) !== 2 || $stateParts[1] !== substr(hash('xxh128', $secret . $stateParts[0]), 12, 8) || time() - (int)$stateParts[0] > 600) {
                return ['mode' => 'return', 'data' => ['type' => 'error', 'message' => '授权请求已过期或无效，请重新发起支付']];
            }

            // 换取用户信息
            $alipay   = Factory::createFromArray(self::formatAlipayConfig($channel));
            $tokenRes = $alipay->execute(['grant_type' => 'authorization_code', 'code' => $authCode], 'alipay.system.oauth.token');

            $userInfo = [
                'user_id'       => $tokenRes['user_id'] ?? null,
                'buyer_open_id' => $tokenRes['open_id'] ?? null,
                'mobile'        => null
            ];

            // 获取用户详细信息（含手机号）
            if ($scope === 'auth_user' && !empty($tokenRes['access_token'])) {
                $userRes            = $alipay->execute(['auth_token' => $tokenRes['access_token']], 'alipay.user.info.share');
                $userInfo['mobile'] = $userRes['mobile'] ?? null;
            }

            return ['mode' => 'success', 'data' => $userInfo];
        } catch (Throwable $e) {
            return ['mode' => 'return', 'data' => ['type' => 'error', 'message' => $e->getMessage()]];
        }
    }

    /**
     * 格式化支付宝配置项
     */
    private static function formatAlipayConfig(array $channel): array
    {
        $appId        = $channel['app_id'];
        $certBasePath = base_path("core/Gateway/Alipay/cert/$appId/");

        return [
            'appId'                   => $appId,
            'privateKey'              => $channel['app_private_key'],
            'alipayPublicKey'         => $channel['alipay_public_key'],
            'alipayPublicKeyFilePath' => "{$certBasePath}alipayCertPublicKey_RSA2.crt",
            'rootCertPath'            => "{$certBasePath}alipayRootCert.crt",
            'appCertPath'             => "{$certBasePath}appCertPublicKey_$appId.crt",
            'certMode'                => $channel['cert_mode'],
            'encryptKey'              => $channel['aes_secret_key']
        ];
    }
}
