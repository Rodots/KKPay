<?php

declare(strict_types=1);

namespace Core\Gateway\STIntl;

use Core\Abstract\GatewayAbstract;
use Core\Gateway\Alipay\Lib\Trait\AlipayOauthTrait;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * 盛天国际网关
 */
class Gateway extends GatewayAbstract
{
    use AlipayOauthTrait;

    /**
     * 网关信息
     */
    public static array $info = [
        'title'       => '盛天国际',
        'author'      => 'KKPay',
        'url'         => 'https://merchant.st166.xyz/',
        'description' => '盛天国际',
        'version'     => '1.0.0',
        'notes'       => '',
        'config'      => [
            [
                'field'       => 'gateway',
                'type'        => 'input',
                'label'       => '域名网关',
                'placeholder' => '必须以http://或https://开头，以/结尾',
                'required'    => true,
                'span'        => 24
            ],
            [
                'field'       => 'merchantId',
                'type'        => 'input',
                'label'       => '商户号',
                'placeholder' => '请输入商户号',
                'required'    => true,
                'maxlength'   => 8,
                'span'        => 24
            ],
            [
                'field'       => 'merchantKey',
                'type'        => 'input',
                'label'       => '对接密钥',
                'placeholder' => '请输入对接密钥',
                'required'    => true,
                'maxlength'   => 64
            ]
        ]
    ];

    /**
     * 验证配置
     */
    protected static function validateConfig(array $config): bool
    {
        return !empty($config['gateway']) && !empty($config['merchantId']) && !empty($config['merchantKey']);
    }

    /**
     * 统一收单交易支付
     */
    public static function unified(array $items): array
    {
        if ($items['order']['payment_type'] === 'WechatPay') {
            return self::wxpay($items);
        }
        return self::alipay($items);
    }

    /**
     * 页面跳转支付
     */
    public static function page(array $items): array
    {
        if ($items['order']['payment_type'] === 'WechatPay') {
            return ['type' => 'redirect', 'extension' => 'wxpay'];
        }
        return ['type' => 'redirect', 'extension' => 'alipay'];
    }

    /**
     * 支付宝支付
     */
    public static function alipay(array $items): array
    {
        // 支付宝用户授权及风控校验（使用公共授权账户模式）
        if (sys_config('payment', 'alipay_get_user_info_qrcode', 'off') === 'on') {
            $redirectUri = site_url(config('kkpay.payment_ext_path', 'cart') . '/alipay/' . $items['order']['trade_no'] . '.html');
            $oauthResult = self::handleAlipayOauthAndRisk([], $items['order'], $redirectUri);
            if ($oauthResult['mode'] === 'return') {
                return $oauthResult['data'];
            } else {
                $items['buyer']['user_id']       = $oauthResult['data']['user_id'] ?: $items['buyer']['user_id'];
                $items['buyer']['buyer_open_id'] = $oauthResult['data']['open_id'] ?: $items['buyer']['buyer_open_id'];
            }
        }

        $res = self::lockPaymentExt($items['order']['trade_no'], function () use ($items) {
            return self::apiExecute('api/commonpay/pay', self::buildPaymentParams($items), $items['channel']);
        });
        var_dump($res);

        return ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => $res['data']]];
    }

    /**
     * 微信支付
     */
    public static function wxpay(array $items): array
    {
        $res = self::lockPaymentExt($items['order']['trade_no'], function () use ($items) {
            return self::apiExecute('api/commonpay/pay', self::buildPaymentParams($items), $items['channel']);
        });

        return ['type' => 'page', 'page' => 'wxpay_qrcode', 'data' => ['url' => $res['data']]];
    }

    /**
     * 构建支付请求参数
     */
    private static function buildPaymentParams(array $items): array
    {
        ['order' => $order, 'buyer' => $buyer] = $items;

        return [
            'merchantId'  => $items['channel']['merchantId'],
            'orderNo'     => $order['trade_no'],
            'money'       => $order['buyer_pay_amount'],
            'timeSpan'    => (int)(microtime(true) * 1000),
            'callBackUrl' => $items['notify_url'],
            'accountName' => $buyer['user_id'] ?: $buyer['buyer_open_id'],
            'ip'          => $buyer['ip'],
            'productName' => $items['subject'],
            'productId'   => 0,
            'returnUrl'   => $items['return_url'],
        ];
    }

    /**
     * 异步通知处理
     */
    public static function notify(array $items): array
    {
        $order   = $items['order'];
        $channel = $items['channel'];
        $request = request();
        $params  = $request->post();

        try {
            $api_sign = $params['sign'];
            unset($params['sign']);
            if ($api_sign !== self::md5Sign($params, $channel['merchantKey'])) {
                return ['type' => 'html', 'data' => 'fail'];
            }

            if (($params['state'] ?? 1) === 0 && ($params['payState'] ?? 1) === 0 && $params['orderNo'] === $order['trade_no'] && bccomp($params['money'], $order['buyer_pay_amount'], 2) === 0) {
                self::processNotify(
                    trade_no: $order['trade_no'],
                    api_trade_no: $params['platOrderNo']
                );
            }

            return ['type' => 'html', 'data' => 'success'];
        } catch (Throwable) {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    /**
     * API 请求执行
     * @throws GuzzleException
     * @throws Exception
     */
    private static function apiExecute(string $path, array $params, array $channel): array
    {
        $httpClient = new Client([
            'base_uri'    => $channel['gateway'],
            'timeout'     => 10,
            'http_errors' => false
        ]);

        $response = $httpClient->post($path, [
            'json' => array_merge($params, ['sign' => self::md5Sign($params, $channel['merchantKey'])]),
        ]);

        $responseBody = $response->getBody()->getContents();
        if (!json_validate($responseBody)) {
            throw new Exception('返回数据格式错误');
        }

        $result = json_decode($responseBody, true);
        if ($result['state'] !== 0 || empty($result['data'])) {
            throw new Exception($result['message']);
        }

        return $result;
    }

    /**
     * 生成MD5签名
     */
    private static function md5Sign(array $params, string $key): string
    {
        ksort($params);
        $signStr = implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($params), $params));
        return strtoupper(md5($signStr . '&key=' . $key));
    }
}
