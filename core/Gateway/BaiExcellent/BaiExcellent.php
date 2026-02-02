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
        'version'     => '1.1.0',
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
     * 验证配置
     */
    protected static function validateConfig(array $config): bool
    {
        return !empty($config['gateway']) && !empty($config['external_id']) && !empty($config['md5_key']) && !empty($config['aes_key']);
    }

    /**
     * 统一收单交易支付
     */
    public static function unified(array $items): array
    {
        return self::alipay($items);
    }

    /**
     * 页面跳转支付
     */
    public static function page(array $items): array
    {
        return ['type' => 'redirect', 'extension' => 'alipay'];
    }

    /**
     * 扫码支付
     */
    public static function alipay(array $items): array
    {
        $res = self::lockPaymentExt($items['order']['trade_no'], function () use ($items) {
            return self::apiExecute('apiv2/payment/pay', self::buildPaymentParams($items), $items['channel']);
        });

        $payUrl = $res['pay_url'];
        $prefix = 'https://www.renrenfu.com/redirectpay?redirecturl=';
        if (str_starts_with($payUrl, $prefix)) {
            $payUrl = substr($payUrl, strlen($prefix));
        }

        // 如果是手机端，则直接跳转
        if (isMobile()) {
            return $res['evoke_mode'] === 1 ? ['type' => 'redirect', 'url' => $payUrl] : ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => $payUrl]];
        }

        return ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => $payUrl]];
    }

    /**
     * 构建支付请求参数
     */
    private static function buildPaymentParams(array $items): array
    {
        ['order' => $order, 'buyer' => $buyer] = $items;

        return [
            'payChannel'           => '1',
            'typeIndex'            => '1',
            'externalId'           => $items['channel']['external_id'],
            'merchantTradeNo'      => $order['trade_no'],
            'totalAmount'          => $order['buyer_pay_amount'],
            'merchantSubject'      => $items['subject'],
            'externalGoodsType'    => '9',
            'timeExpire'           => $order['close_time'],
            'quitUrl'              => 'https://www.alipay.com',
            'buyerId'              => $buyer['user_id'] ?: $buyer['buyer_open_id'],
            'buyerMinAge'          => $buyer['min_age'],
            'merchantPayNotifyUrl' => $items['notify_url'],
            'qrPayMode'            => '4',
            'accountName'          => $buyer['real_name'],
            'accountPhone'         => $buyer['mobile'],
            // 'clientIp'             => '8.8.8.8',
            'clientIp'             => $buyer['ip'],
            'riskControlNotifyUrl' => $items['notify_url'],
        ];
    }

    /**
     * 异步通知处理
     */
    public static function notify(array $items): array
    {
        $order     = $items['order'];
        $channel   = $items['channel'];
        $request   = request();
        $params    = $request->post();
        $timeStamp = $request->header('timeStamp');
        $visitAuth = $request->header('visitAuth');

        if (empty($visitAuth)) {
            return ['type' => 'html', 'data' => 'error auth'];
        }

        try {
            $aes          = self::createAes($channel);
            $expectedAuth = md5($channel['md5_key'] . ':' . $timeStamp);

            if ($expectedAuth !== $aes->decrypt($visitAuth) && ($params['pltNotifySign'] ?? '') !== self::generateSign($params, $visitAuth, $channel['aes_key'])) {
                return ['type' => 'html', 'data' => 'fail'];
            }

            if (($params['tradeStatus'] ?? '') === 'TRADE_SUCCESS' && $params['merchantTradeNo'] === $order['trade_no'] && bccomp($params['totalAmount'], $order['buyer_pay_amount'], 2) === 0) {
                self::processNotify(
                    trade_no: $order['trade_no'],
                    api_trade_no: $params['platformOutTradeNo'],
                    bill_trade_no: $params['thirdOutTradeNo'],
                    buyer: ['user_id' => $params['buyerUserId'] ?: null]
                );
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
        $params = [
            'externalId'      => $channel['external_id'],
            'merchantTradeNo' => $order['trade_no'],
            'refundAmount'    => $refund_record['amount'],
            'refundReason'    => $refund_record['reason']
        ];

        try {
            $result = self::apiExecute('apiv2/refund/tradeRefund', $params, $channel);
            return ['state' => true, 'api_refund_no' => $result['platformOutTradeNo'], 'refund_fee' => $result['refundAmount']];
        } catch (Throwable $e) {
            return ['state' => false, 'message' => $e->getMessage()];
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

        $timestamps = time();
        $visitAuth  = self::generateVisitAuth($timestamps, $channel);

        $response = $httpClient->post($path, [
            'headers'     => ['timeStamp' => $timestamps, 'visitAuth' => $visitAuth],
            'form_params' => array_merge($params, ['sign' => self::generateSign($params, $visitAuth, $channel['aes_key'])]),
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

    /**
     * 生成访问认证
     */
    private static function generateVisitAuth(int $timestamps, array $channel): string
    {
        $plainText = md5($channel['md5_key'] . ':' . $timestamps);
        return self::createAes($channel)->encrypt($plainText);
    }

    /**
     * 生成签名
     */
    private static function generateSign(array $params, string $visitAuth, string $aesKey): string
    {
        ksort($params);
        $filtered = array_filter($params, fn($v, $k) => $k !== 'sign' && $v !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);

        $signStr = implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($filtered), $filtered));
        return md5($signStr . $visitAuth . substr($aesKey, 0, 12));
    }

    /**
     * 创建 AES 实例
     */
    private static function createAes(array $channel): Aes
    {
        return new Aes($channel['aes_key'], substr($channel['aes_key'], 0, 16));
    }
}
