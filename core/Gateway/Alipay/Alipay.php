<?php

declare(strict_types=1);

namespace Core\Gateway\Alipay;

use app\model\OrderBuyer;
use app\model\PaymentChannelAccount;
use Core\Gateway\AbstractGateway;
use Core\Gateway\Alipay\Lib\Factory;
use Core\Service\RiskService;
use Throwable;

/**
 * 支付宝支付网关
 * 支持多种支付产品类型的扩展
 */
class Alipay extends AbstractGateway
{
    /**
     * 网关信息
     */
    public static array $info = [
        'title'       => '支付宝支付',
        'author'      => 'Rodots',
        'url'         => 'https://opendocs.alipay.com/open-v3/08c7f9f8_alipay.trade.pay',
        'description' => '蚂蚁集团旗下的支付宝，是以每个人为中心，以实名和信任为基础的生活平台。自2004年成立以来，支付宝已经与超过200家金融机构达成合作，为上千万小微商户提供支付服务。随着场景拓展和产品创新，拓展的服务场景不断增加，支付宝已发展成为融合了支付、生活服务、政务服务、理财、保险、公益等多个场景与行业的开放性平台。支付宝还推出了跨境支付、退税等多项服务，让中国用户在境外也能享受移动支付的便利。',
        'version'     => '1.0.1',
        'notes'       => '<p>选择可用的支付类型，注意只能选择已经签约的产品，否则会无法支付！</p><p>如果使用<span class="text-green-600">证书</span>模式对接，需将<span class="text-green-600">应用公钥证书</span>、<span class="text-green-600">支付宝公钥证书</span>、<span class="text-green-600">支付宝根证书</span>共<b>3</b>个<span class="text-destructive">.crt</span>文件放置于<span class="text-blue-600">/core/Gateway/Alipay/cert/<b>{支付宝AppID}</b>/</span>文件夹</p>',
        'config'      => [
            [
                'field'       => 'app_id',
                'type'        => 'input',
                'label'       => 'AppID',
                'placeholder' => '请输入支付宝AppID',
                'required'    => true,
                'maxlength'   => 32
            ],
            [
                'field'       => 'app_private_key',
                'type'        => 'textarea',
                'label'       => '应用私钥',
                'placeholder' => '请输入应用私钥',
                'required'    => true,
                'maxlength'   => 2048
            ],
            [
                'field'       => 'alipay_public_key',
                'type'        => 'textarea',
                'label'       => '支付宝公钥',
                'placeholder' => '请输入支付宝公钥，填错也可以支付成功但会导致无法回调，如果用【证书模式】对接此处可留空不填',
                'maxlength'   => 2048
            ],
            [
                'field'        => 'cert_mode',
                'type'         => 'radio',
                'label'        => '接口加签方式',
                'required'     => true,
                'options'      => [
                    ['label' => '密钥模式', 'value' => 0],
                    ['label' => '证书模式', 'value' => 1]
                ],
                'defaultValue' => 0
            ],
            [
                'field'        => 'payment_types',
                'type'         => 'checkbox',
                'label'        => '支付类型',
                'required'     => true,
                'options'      => [
                    ['label' => '当面付(付款码支付)', 'value' => 'scan'],
                    ['label' => '当面付扫码', 'value' => 'dmfscan'],
                    ['label' => '订单码支付', 'value' => 'ddm'],
                    ['label' => 'APP 支付', 'value' => 'app'],
                    ['label' => '手机网站支付', 'value' => 'wap'],
                    ['label' => '电脑网站支付', 'value' => 'pc'],
                    ['label' => 'JSAPI 支付', 'value' => 'jsapi'],
                ],
                'defaultValue' => ['ddm']
            ],
            [
                'field'       => 'aes_secret_key',
                'type'        => 'input',
                'label'       => '内容加密密钥',
                'placeholder' => '请输入在支付宝开放平台设置的AES密钥（接口内容加密）',
                'maxlength'   => 512,
                'span'        => 24,
                'tooltip'     => '可选项，如未在开放平台设置，则不需要填写'
            ]
        ]
    ];

    /**
     * 验证配置
     */
    protected static function validateConfig(array $config): bool
    {
        return !empty($config['app_id']) && !empty($config['app_private_key']) && !empty($config['alipay_public_key']);
    }

    /**
     * 统一收单交易支付
     * @param array $items
     * @return array
     */
    public static function unified(array $items): array
    {
        $order         = $items['order'];
        $method        = $items['method'];
        $payment_types = $items['channel']['payment_types'];

        return match (true) {
            in_array($method, ['jsapi', 'miniprogram']) && in_array('jsapi', $payment_types) => self::jsapi($items),
            $method === 'app' && in_array('app', $payment_types) => self::app($items),
            $method === 'scan' && in_array('scan', $payment_types) => self::scan($items),
            in_array('dmfscan', $payment_types) || in_array('ddm', $payment_types) => self::qrcode($items),
            default => ['type' => 'redirect', 'url' => '/pay/web/' . $order['trade_no'] . '.html']
        };
    }

    /**
     * 页面跳转支付
     * @param array $items
     * @return array
     */
    public static function page(array $items): array
    {
        $order         = $items['order'];
        $payment_types = $items['channel']['payment_types'];
        $trade_no      = $order['trade_no'];

        // JSAPI 支付需要特殊处理：在支付宝环境内直接调用，否则先跳转获取用户标识
        if (in_array('jsapi', $payment_types)) {
            return isAlipay() ? self::jsapi($items) : ['type' => 'redirect', 'url' => '/pay/jsapi/' . $trade_no . '.html'];
        }

        return match (true) {
            in_array('app', $payment_types) => ['type' => 'redirect', 'url' => '/pay/app/' . $trade_no . '.html'],
            isMobile() && in_array('wap', $payment_types) => ['type' => 'redirect', 'url' => '/pay/wap/' . $trade_no . '.html'],
            in_array('pc', $payment_types) => ['type' => 'redirect', 'url' => '/pay/pc/' . $trade_no . '.html'],
            in_array('dmfscan', $payment_types) || in_array('ddm', $payment_types) => ['type' => 'redirect', 'url' => '/pay/qrcode/' . $trade_no . '.html'],
            default => ['type' => 'error', 'message' => '暂未匹配到可用的支付产品，请选择其他支付方式或稍后重试！']
        };
    }

    /**
     * 网页支付（手机网站、电脑网站产品）
     * @param array $items
     * @return array
     */
    public static function web(array $items): array
    {
        $payment_types = $items['channel']['payment_types'];
        if (isMobile() && in_array('wap', $payment_types)) {
            return self::wap($items);
        } elseif (in_array('pc', $payment_types)) {
            return self::pc($items);
        }

        return ['type' => 'error', 'message' => '暂未匹配到可用的支付产品，请选择其他支付方式或稍后重试！'];
    }

    /**
     * 手机网站支付
     * @param array $items
     * @return array
     */
    public static function wap(array $items): array
    {
        $order   = $items['order'];
        $channel = $items['channel'];

        // 处理支付宝用户授权及风控检查（仅当配置开启且无用户标识时）
        if (sys_config('payment', 'alipay_get_user_info_wap', 'off') === 'on') {
            $oauthResult = self::handleAlipayOauthAndRisk($channel, $order);
            if ($oauthResult !== null) {
                return $oauthResult;
            }
        }

        $alipay = Factory::createFromArray(self::formatConfig($items['channel']));

        $params = [
            'out_trade_no' => $order['trade_no'],
            'total_amount' => $order['buyer_pay_amount'],
            'subject'      => $items['subject'],
            'product_code' => 'QUICK_WAP_WAY'
        ];

        return self::lockPaymentExt($order['trade_no'], function () use ($alipay, $params, $items) {
            $html = $alipay->pageExecute($params, 'alipay.trade.wap.pay', $items['return_url'], $items['notify_url']);
            return ['type' => 'html', 'template' => $html];
        });
    }

    /**
     * 电脑网站支付
     * @param array $items
     * @return array
     */
    public static function pc(array $items): array
    {
        $order = $items['order'];

        $alipay = Factory::createFromArray(self::formatConfig($items['channel']));

        $params = [
            'out_trade_no' => $order['trade_no'],
            'total_amount' => $order['buyer_pay_amount'],
            'subject'      => $items['subject'],
            'product_code' => 'FAST_INSTANT_TRADE_PAY'
        ];

        return self::lockPaymentExt($order['trade_no'], function () use ($alipay, $params, $items) {
            $html = $alipay->pageExecute($params, 'alipay.trade.page.pay', $items['return_url'], $items['notify_url']);
            return ['type' => 'html', 'template' => $html];
        });
    }

    /**
     * 扫码支付（订单码支付、当面付扫码支付）
     * @param array $items
     * @return array
     */
    public static function qrcode(array $items): array
    {
        $order   = $items['order'];
        $channel = $items['channel'];
        $buyer   = $items['buyer'];

        // 处理支付宝用户授权及风控检查（仅当配置开启且无用户标识时）
        if (sys_config('payment', 'alipay_get_user_info_qrcode', 'off') === 'on') {
            $oauthResult = self::handleAlipayOauthAndRisk($channel, $order);
            if ($oauthResult !== null) {
                return $oauthResult;
            }
        }

        $alipay = Factory::createFromArray(self::formatConfig($channel));
        $params = [
            'out_trade_no'      => $order['trade_no'],
            'total_amount'      => $order['buyer_pay_amount'],
            'subject'           => $items['subject'],
            'merchant_order_no' => $order['out_trade_no']
        ];

        if (in_array('ddm', $channel['payment_types'])) {
            $params['product_code']    = 'QR_CODE_OFFLINE';
            $params['business_params'] = ['mc_create_trade_ip' => $buyer['ip']];
        }

        return self::lockPaymentExt($order['trade_no'], function () use ($alipay, $params, $items) {
            $res = $alipay->v1Execute($params, 'alipay.trade.precreate', $items['return_url'], $items['notify_url']);
            return isAlipay() ? ['type' => 'location', 'url' => $res['qr_code']] : ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => $res['qr_code']]];
        });
    }

    /**
     * JSAPI 支付
     * @param array $items
     * @return array
     */
    public static function jsapi(array $items): array
    {
        $order = $items['order'];

        $alipay = Factory::createFromArray(self::formatConfig($items['channel']));

        $params = [
            'out_trade_no'    => $order['trade_no'],
            'total_amount'    => $order['buyer_pay_amount'],
            'subject'         => $items['subject'],
            'product_code'    => 'JSAPI_PAY',
            'business_params' => ['mc_create_trade_ip' => $items['buyer']['ip']]
        ];

        return self::lockPaymentExt($order['trade_no'], function () use ($alipay, $params, $items) {
            $html = $alipay->v1Execute($params, 'alipay.trade.create', $items['return_url'], $items['notify_url']);
            return ['type' => 'html', 'data' => $html];
        });
    }

    /**
     * APP 支付
     * @param array $items
     * @return array
     */
    public static function app(array $items): array
    {
        return ['type' => 'error', 'message' => '功能开发中'];
    }

    /**
     * 当面付（付款码支付）[被扫]
     *
     * 因为只能通过统一收单交易支付接口调用此方法，所以需要设为私有方法
     *
     * @param array $items
     * @return array
     */
    private static function scan(array $items): array
    {
        // 只能通过统一收单交易支付接口调用此方法，判断是否为路由直接请求，如果是则拦截并返回404页面
        if ($items['isExtension']) {
            return ['type' => 'page', 'message' => '404'];
        }

        return ['type' => 'error', 'message' => '功能开发中'];
    }

    /**
     * 异步通知处理
     * @param array $items
     * @return array
     */
    public static function notify(array $items): array
    {
        $order = $items['order'];
        $post  = request()->post();

        try {
            $alipay = Factory::createFromArray(self::formatConfig($items['channel']));

            // 通过ConfigManager获取SignatureManager实例
            $signatureManager = $alipay->getConfigManager()->getSignatureManager();

            // 验证回调参数签名
            if (!$signatureManager->verifyParams($post)) {
                return ['type' => 'html', 'data' => 'fail'];
            }

            if (in_array($post['trade_status'], ['TRADE_FINISHED', 'TRADE_SUCCESS'], true)) {
                if ($post['out_trade_no'] == $order['trade_no'] && bccomp($post['total_amount'], $order['buyer_pay_amount'], 2) === 0) {
                    // 买家支付宝信息
                    $buyer = [
                        'user_id'       => empty($post['buyer_id']) ? null : $post['buyer_id'],
                        'buyer_open_id' => empty($post['buyer_open_id']) ? null : $post['buyer_open_id'],
                    ];
                    // 处理支付异步通知
                    self::processNotify(trade_no: $order['trade_no'], api_trade_no: $post['trade_no'], payment_time: $post['gmt_payment'], buyer: $buyer);
                }
            }

            return ['type' => 'html', 'data' => 'success'];
        } catch (Throwable) {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    /**
     * 交易退款
     * @param array $order
     * @param array $channel
     * @param array $refund_record
     * @return array
     */
    public static function refund(array $order, array $channel, array $refund_record): array
    {
        $alipay = Factory::createFromArray(self::formatConfig($channel));

        $params = [
            'refund_amount'  => $refund_record['amount'],
            'out_trade_no'   => $order['trade_no'],
            'trade_no'       => $order['api_trade_no'],
            'refund_reason'  => $refund_record['reason'],
            'out_request_no' => $refund_record['id']
        ];

        try {
            $result = $alipay->execute($params, 'alipay.trade.refund');
        } catch (Throwable $e) {
            return ['state' => false, 'message' => $e->getMessage()];
        }
        return ['state' => true, 'api_refund_no' => $result['trade_no'], 'refund_fee' => $result['refund_fee'], 'buyer' => (empty($result['buyer_user_id']) ? $result['buyer_open_id'] : $result['buyer_user_id'])];
    }

    /**
     * 格式化配置项
     */
    private static function formatConfig(array $channel): array
    {
        $certBasePath = base_path('core/Gateway/Alipay/cert/' . $channel['app_id'] . '/');
        return [
            'appId'                   => $channel['app_id'],
            'privateKey'              => $channel['app_private_key'],
            'alipayPublicKey'         => $channel['alipay_public_key'],
            'alipayPublicKeyFilePath' => $certBasePath . 'alipayCertPublicKey_RSA2.crt',
            'rootCertPath'            => $certBasePath . 'alipayRootCert.crt',
            'appCertPath'             => $certBasePath . 'appCertPublicKey_' . $channel['app_id'] . '.crt',
            'certMode'                => $channel['cert_mode'],
            'encryptKey'              => $channel['aes_secret_key']
        ];
    }

    /**
     * 处理支付宝用户授权及风控检查
     *
     * 执行用户授权流程，获取用户信息后更新订单买家数据并进行风控黑名单检查
     * 可在多个支付方式中复用（如 qrcode、jsapi 等）
     *
     * @param array $channel 支付渠道配置
     * @param array $order   订单信息
     * @return array|null 需要返回给调用方的结果（重定向/错误），null 表示继续执行后续逻辑
     */
    private static function handleAlipayOauthAndRisk(array $channel, array $order): ?array
    {
        $oauth = self::alipayOauth($channel);
        if (!$oauth) {
            return null;
        }

        // 需要重定向到授权页面
        if ($oauth['mode'] === 'return') {
            return $oauth['data'];
        }

        // 授权成功，更新买家信息（仅更新非 null 字段）
        $alipayInfo = $oauth['data'];
        $updateData = array_filter([
            'user_id'       => $alipayInfo['user_id'],
            'buyer_open_id' => $alipayInfo['buyer_open_id'],
            'mobile'        => $alipayInfo['mobile']
        ], fn($value) => $value !== null);

        if (!empty($updateData)) {
            OrderBuyer::where('trade_no', $order['trade_no'])->update($updateData);
        }

        // 用户ID黑名单检查
        if (RiskService::checkUserIdBlacklist($order['merchant_id'], $alipayInfo['user_id'], $alipayInfo['buyer_open_id'], $order['trade_no'])) {
            return ['type' => 'error', 'message' => '系统异常，无法完成付款'];
        }

        // 手机号黑名单检查
        if (!empty($alipayInfo['mobile']) && RiskService::checkMobileBlacklist($alipayInfo['mobile'], $order['merchant_id'], $order['trade_no'])) {
            return ['type' => 'error', 'message' => '系统异常，无法完成付款'];
        }

        return null;
    }

    /**
     * 支付宝用户授权认证
     *
     * 用于获取支付宝用户的 user_id/open_id 及手机号等信息
     * 支持两种模式：current（使用当前渠道配置）、common（使用公共授权账户）
     *
     * @param array $channel 支付渠道配置
     * @return array|false 授权结果或失败
     */
    protected static function alipayOauth(array $channel = []): array|false
    {
        try {
            $paymentConfig = sys_config('payment');
            $oauthMode     = $paymentConfig['alipay_get_user_info_mode'] ?? 'current';
            $scope         = $paymentConfig['alipay_get_user_info_scope'] ?? 'auth_base';

            // 确定授权使用的渠道配置
            if ($oauthMode === 'common') {
                $accountId = $paymentConfig['alipay_get_user_info_common_account'] ?? 0;
                $row       = PaymentChannelAccount::find($accountId, ['config']);
                if (!$row) {
                    return false;
                }
                $channel = $row->config;
            } elseif (empty($channel)) {
                return false;
            }

            $appId = $channel['app_id'] ?? '';
            if (empty($appId)) {
                return false;
            }

            $request  = request();
            $authCode = $request->get('auth_code');
            $secret   = $channel['app_private_key'] . $appId;

            if (empty($authCode)) {
                $ts          = time();
                $state       = $ts . '.' . substr(hash('xxh128', $secret . $ts), 12, 8);
                $redirectUri = urlencode((is_https() ? 'https:' : 'http:') . $request->fullUrl());
                $authUrl     = "https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id=$appId&scope=$scope&state=$state&redirect_uri=$redirectUri";
                return ['mode' => 'return', 'data' => isAlipay() ? ['type' => 'location', 'url' => $authUrl] : ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => $authUrl]]];
            }

            // 校验 state（格式：timestamp.hash）
            [$ts, $sign] = explode('.', $request->get('state', '0.'), 2);
            if ($sign !== substr(hash('xxh128', $secret . $ts), 12, 8) || time() - (int)$ts > 600) {
                return ['mode' => 'return', 'data' => ['type' => 'error', 'message' => '授权请求已过期或无效，请重新发起支付']];
            }

            // 有授权码时，换取用户信息
            $alipay   = Factory::createFromArray(self::formatConfig($channel));
            $tokenRes = $alipay->execute(['grant_type' => 'authorization_code', 'code' => $authCode], 'alipay.system.oauth.token');

            $userInfo = [
                'user_id'       => $tokenRes['user_id'] ?? null,
                'buyer_open_id' => $tokenRes['open_id'] ?? null,
                'mobile'        => null
            ];

            // 如需获取用户详细信息（含手机号）
            if ($scope === 'auth_user' && !empty($tokenRes['access_token'])) {
                $userRes            = $alipay->execute(['auth_token' => $tokenRes['access_token']], 'alipay.user.info.share');
                $userInfo['mobile'] = $userRes['mobile'] ?? null;
            }

            return ['mode' => 'success', 'data' => $userInfo];
        } catch (Throwable $e) {
            return ['mode' => 'return', 'data' => ['type' => 'error', 'message' => $e->getMessage()]];
        }
    }
}
