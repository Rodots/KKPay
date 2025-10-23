<?php
declare(strict_types = 1);

namespace Core\Gateway\Alipay;

use Core\Gateway\AbstractGateway;
use Gateway\Alipay\Factory;
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
        'version'     => '1.0.0',
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
                'placeholder' => '请输入支付宝公钥，填错也可以支付成功但会导致无法回调，如果用公钥证书模式此处可留空不填',
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
                    ['label' => '当面付', 'value' => 'dmf'],
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
     * 页面跳转支付
     * @param array $items
     * @return array
     */
    public static function submit(array $items): array
    {
        $order         = $items['order'];
        $payment_types = $items['channel']['payment_types'];

        $isMobile = isMobile();
        $isAlipay = isAlipay();

        if ($isAlipay && in_array('jsapi', $payment_types)) {
            // 因为要先获取到买家支付宝用户唯一标识，所以要先跳转到新地址获取
            return ['type' => 'redirect', 'url' => '/pay/jsapi/' . $order['trade_no'] . '.html'];
        } elseif (in_array('wap', $payment_types) && $isMobile) {
            return self::wap($items);
        } elseif (in_array('pc', $payment_types)) {
            return self::pc($items);
        } elseif (in_array('ddm', $payment_types)) {
            return ['type' => 'redirect', 'url' => '/pay/ddm/' . $order['trade_no'] . '.html'];
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
        $order = $items['order'];

        try {
            $alipay = Factory::createFromArray(self::formatConfig($items['channel']));

            $params = [
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['buyer_pay_amount'],
                'subject'      => $items['subject'],
                'product_code' => 'QUICK_WAP_WAY'
            ];

            return self::lockPaymentExt($order['trade_no'], function () use ($alipay, $params, $items) {
                $html = $alipay->pageExecute($params, 'alipay.trade.wap.pay', $items['return_url'], $items['notify_url']);
                return ['type' => 'html', 'data' => $html];
            });
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * 电脑网站支付
     * @param array $items
     * @return array
     */
    public static function pc(array $items): array
    {
        $order = $items['order'];

        try {
            $alipay = Factory::createFromArray(self::formatConfig($items['channel']));

            $params = [
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['buyer_pay_amount'],
                'subject'      => $items['subject'],
                'product_code' => 'FAST_INSTANT_TRADE_PAY'
            ];

            return self::lockPaymentExt($order['trade_no'], function () use ($alipay, $params, $items) {
                $html = $alipay->pageExecute($params, 'alipay.trade.page.pay', $items['return_url'], $items['notify_url']);
                return ['type' => 'html', 'data' => $html];
            });
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * 订单码支付
     * @param array $items
     * @return array
     */
    public static function ddm(array $items): array
    {
        $order = $items['order'];

        try {
            $alipay = Factory::createFromArray(self::formatConfig($items['channel']));

            $params = [
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['buyer_pay_amount'],
                'subject'      => $items['subject'],
                'product_code' => 'QR_CODE_OFFLINE'
            ];

            return self::lockPaymentExt($order['trade_no'], function () use ($alipay, $params, $items) {
                $res = $alipay->v1Execute($params, 'alipay.trade.precreate', $items['return_url'], $items['notify_url']);
                return ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => $res['qr_code']]];
            });
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * JSAPI 支付
     * @param array $items
     * @return array
     */
    public static function jsapi(array $items): array
    {
        $order = $items['order'];
        try {
            $alipay = Factory::createFromArray(self::formatConfig($items['channel']));

            $params = [
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['buyer_pay_amount'],
                'subject'      => $items['subject'],
                'product_code' => 'JSAPI_PAY'
            ];

            return self::lockPaymentExt($order['trade_no'], function () use ($alipay, $params, $items) {
                $html = $alipay->v1Execute($params, 'alipay.trade.create', $items['return_url'], $items['notify_url']);
                return ['type' => 'html', 'data' => $html];
            });
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }
    }

    public static function notify(array $items): array
    {
        return ['type' => 'html', 'data' => 'ok'];
    }

    public static function refund(array $items): array
    {
        return ['type' => 'html', 'data' => 'ok'];
    }

    /*
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

    protected static function validateConfig(array $config): bool
    {
        return !empty($config['app_id']) &&
            !empty($config['app_private_key']) &&
            !empty($config['alipay_public_key']);
    }
}
