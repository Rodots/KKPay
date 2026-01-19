<?php

declare(strict_types=1);

namespace Core\Gateway\BaiExcellent;

use Core\Gateway\AbstractGateway;
use Core\Gateway\BaiExcellent\lib\Aes;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;
use GuzzleHttp\Client;

/**
 * 百优支付网关
 */
class BaiExcellent extends AbstractGateway
{
    /**
     * 网关信息
     */
    public static array $info = [
        'title'       => '百优支付',
        'author'      => 'Rodots',
        'url'         => 'https://doc.renrenfu.com/',
        'description' => '百优支付',
        'version'     => '1.0.0',
        'notes'       => '',
        'config'      => [
            [
                'field'       => 'gateway',
                'type'        => 'input',
                'label'       => '域名网关',
                'placeholder' => '请输入对接的平台域名地址',
                'required'    => true,
                'span'        => 24
            ],
            [
                'field'       => 'external_id',
                'type'        => 'input',
                'label'       => '商户编号',
                'placeholder' => '请输入商户编号',
                'required'    => true,
                'maxlength'   => 8,
                'span'        => 24
            ],
            [
                'field'       => 'md5_key',
                'type'        => 'input',
                'label'       => 'MD5密钥',
                'placeholder' => '请输入MD5密钥',
                'required'    => true,
                'maxlength'   => 64
            ],
            [
                'field'       => 'aes_key',
                'type'        => 'input',
                'label'       => 'AES密钥',
                'placeholder' => '请输入AES密钥',
                'required'    => true,
                'maxlength'   => 64
            ]
        ]
    ];

    /**
     * 统一收单交易支付
     * @param array $items
     * @return array
     */
    public static function unified(array $items): array
    {
        return self::alipay($items);
    }

    /**
     * 页面跳转支付
     * @param array $items
     * @return array
     */
    public static function page(array $items): array
    {
        return ['type' => 'redirect', 'url' => '/pay/alipay/' . $items['order']['trade_no'] . '.html'];
    }

    /**
     * 扫码支付
     * @param array $items
     * @return array
     */
    public static function alipay(array $items): array
    {
        if (!isMobile()) {
            return ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => site_url('pay/alipay/' . $items['order']['trade_no'] . '.html')]];
        }

        $order   = $items['order'];
        $channel = $items['channel'];
        $buyer   = $items['buyer'];

        $params = [
            'payChannel'           => '1',
            'typeIndex'            => '1',
            'externalId'           => $channel['external_id'],
            'merchantTradeNo'      => $order['trade_no'],
            'totalAmount'          => $order['buyer_pay_amount'],
            'merchantSubject'      => $items['subject'],
            'externalGoodsType'    => '9',
            'timeExpire'           => $order['close_time'],
            'quitUrl'              => 'https://www.alipay.com',
            'buyerId'              => $buyer['user_id'] ?: $buyer['buyer_open_id'],
            'buyerMinAge'          => $buyer['min_age'],
            'merchantPayNotifyUrl' => $items['notify_url'],
            'accountName'          => $buyer['real_name'],
            'accountPhone'         => $buyer['mobile'],
            'clientIp'             => $buyer['ip'],
            'riskControlNotifyUrl' => $items['notify_url'],
        ];

        return self::lockPaymentExt($order['trade_no'], function () use ($params, $channel) {
            $res = self::apiExecute('apiv2/payment/pay', $params, $channel);
            if ($res['evoke_mode'] === 1) {
                return ['type' => 'redirect', 'url' => $res['pay_url']];
            }
            return ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => $res['pay_url']]];
        });
    }

    /*
     * 统一对接方法
     */
    /**
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

        $timestamps = time();
        $visitAuth  = self::visitAuth($timestamps, $channel);
        $response   = $httpClient->post($path, [
            'headers'     => [
                'timeStamp' => $timestamps,
                'visitAuth' => $visitAuth
            ],
            'form_params' => array_merge($params, ['sign' => self::sign($params, $visitAuth, $channel['aes_key'])]),
        ]);

        $responseBody = $response->getBody()->getContents();
        if (!json_validate($responseBody)) {
            throw new Exception('返回数据格式错误');
        }
        $result = json_decode($responseBody, true);

        if ($result['code'] !== 0) {
            throw new Exception($result['msg']);
        }
        return $result['data']['data'];
    }

    private static function visitAuth(int|string $timestamps, array $channel): string
    {
        $plainText = md5($channel['md5_key'] . ':' . $timestamps);
        $iv        = substr($channel['aes_key'], 0, 16);
        $aes       = new Aes($channel['aes_key'], 'AES-192-CBC', $iv);

        return $aes->encrypt($plainText);
    }

    private static function sign(array $params, string $visitAuth, string $aes_key): string
    {
        ksort($params);
        $params   = array_filter(
            $params,
            fn($v, $k) => $k !== 'sign' && $v !== '' && $v !== null,
            ARRAY_FILTER_USE_BOTH
        );
        $sign_str = '';
        foreach ($params as $k => $v) {
            $sign_str .= "$k=$v&";
        }
        return md5(rtrim($sign_str, '&') . $visitAuth . substr($aes_key, 0, 12));
    }

    /**
     * 异步通知处理
     * @param array $items
     * @return array
     */
    public static function notify(array $items): array
    {
        $order     = $items['order'];
        $channel   = $items['channel'];
        $post      = request()->post();
        $timeStamp = request()->header('timeStamp');
        $visitAuth = request()->header('visitAuth');

        if (empty($visitAuth)) {
            return ['type' => 'html', 'data' => 'error auth'];
        }

        $plainText = md5($channel['md5_key'] . ':' . $timeStamp);
        $iv        = substr($channel['aes_key'], 0, 16);

        try {
            $aes = new Aes($channel['aes_key'], 'AES-192-CBC', $iv);

            if ($plainText !== $aes->decrypt($visitAuth)) {
                return ['type' => 'html', 'data' => 'fail'];
            }

            if (isset($post['tradeStatus']) && $post['tradeStatus'] === 'TRADE_SUCCESS') {
                if ($post['merchantTradeNo'] === $order['trade_no'] && bccomp($post['totalAmount'], $order['buyer_pay_amount'], 2) === 0) {
                    // 买家支付宝信息
                    $buyer = [
                        'user_id' => $post['buyerUserId'] ?: null,
                    ];
                    // 处理支付异步通知
                    self::processNotify(trade_no: $order['trade_no'], api_trade_no: $post['platformOutTradeNo'], bill_trade_no: $post['thirdOutTradeNo'], payment_time: $post['gmt_payment'], buyer: $buyer);
                }
            }

            return ['type' => 'html', 'data' => 'success'];
        } catch (Throwable) {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    /*
     * 订单退款
     */
    public static function refund(array $items): array
    {
        $order         = $items['order'];
        $refund_record = $items['refund_record'];
        $channel       = $items['channel'];

        $params = [
            'externalId'      => $channel['external_id'],
            'merchantTradeNo' => $order['trade_no'],
            'refundAmount'    => $refund_record['amount'],
            'refundReason'    => $refund_record['reason']
        ];

        try {
            $result = self::apiExecute('apiv2/refund/tradeRefund', $params, $items['channel']);
        } catch (Throwable $e) {
            return ['state' => false, 'message' => $e->getMessage()];
        }
        return ['state' => true, 'api_refund_no' => $result['platformOutTradeNo'], 'refund_fee' => $result['refundAmount']];
    }

    protected static function validateConfig(array $config): bool
    {
        return !empty($config['gateway']) && !empty($config['external_id']) && !empty($config['md5_key']) && !empty($config['aes_key']);
    }
}
