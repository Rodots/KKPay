<?php

declare(strict_types=1);

namespace Core\Gateway\EPay;

use Core\Gateway\AbstractGateway;
use Core\Gateway\Alipay\Lib\Trait\AlipayOauthTrait;
use Core\Gateway\EPay\Lib\EpayCore;
use Exception;
use Throwable;

/**
 * 彩虹易支付V2支付网关
 */
class EPay extends AbstractGateway
{
    use AlipayOauthTrait;

    /**
     * 网关信息
     */
    public static array $info = [
        'title'       => '彩虹易支付V2',
        'author'      => 'Rodots',
        'url'         => 'https://pay.v8jisu.cn/doc/index.html',
        'description' => '彩虹易支付系统是一款专业的聚合支付系统，支持支付宝，微信，QQ钱包等多种支付方式，提供安全，高效，简单的支付服务。',
        'version'     => '1.0.0',
        'notes'       => '',
        'config'      => [
            [
                'field'       => 'api_url',
                'type'        => 'input',
                'label'       => '接口地址',
                'placeholder' => '必须以http://或https://开头，以/结尾',
                'required'    => true,
                'maxlength'   => 32
            ],
            [
                'field'       => 'merchant_id',
                'type'        => 'input',
                'label'       => '商户ID',
                'placeholder' => '请输入商户ID',
                'required'    => true
            ],
            [
                'field'       => 'public_key',
                'type'        => 'textarea',
                'label'       => '平台公钥',
                'placeholder' => '请输入平台公钥',
                'required'    => true,
                'maxlength'   => 2048
            ],
            [
                'field'       => 'private_key',
                'type'        => 'textarea',
                'label'       => '商户私钥',
                'placeholder' => '请输入商户私钥',
                'required'    => true,
                'maxlength'   => 2048
            ],
            [
                'field'        => 'is_mapi',
                'type'         => 'radio',
                'label'        => 'mapi接口',
                'required'     => true,
                'options'      => [
                    ['label' => '不使用', 'value' => 0],
                    ['label' => '使用', 'value' => 1]
                ],
                'defaultValue' => 0
            ]
        ]
    ];

    /**
     * 验证配置
     */
    protected static function validateConfig(array $config): bool
    {
        return !empty($config['api_url']) && !empty($config['merchant_id']) && !empty($config['public_key']) && !empty($config['private_key']) && !empty($config['is_mapi']);
    }

    /**
     * 统一收单交易支付
     * @param array $items
     * @return array
     */
    static public function unified(array $items): array
    {
        $order   = $items['order'];
        $channel = $items['channel'];

        if (isset($channel['is_mapi']) && $channel['is_mapi'] === 1) {
            try {
                $type = self::getPayType($order['payment_type']);
            } catch (Exception $e) {
                return ['type' => 'error', 'message' => $e->getMessage()];
            }
            return self::$type($items);
        }
        return ['type' => 'redirect', 'extension' => 'page'];
    }

    /**
     * 页面跳转支付
     * @param array $items
     * @return array
     */
    static public function page(array $items): array
    {
        $order   = $items['order'];
        $channel = $items['channel'];

        try {
            $type = self::getPayType($order['payment_type']);

            if (isset($channel['is_mapi']) && $channel['is_mapi'] === 1) {
                return ['type' => 'redirect', 'extension' => $type];
            }
            $params = [
                "type"         => $type,
                "notify_url"   => $items['notify_url'],
                "return_url"   => $items['return_url'],
                "out_trade_no" => $order['trade_no'],
                "name"         => $items['subject'],
                "money"        => $order['buyer_pay_amount']
            ];

            $epay = new EpayCore($channel);
            if (is_https() && str_starts_with($channel['api_url'], 'http://')) {
                return ['type' => 'location', 'url' => $epay->getPayLink($params)];
            } else {
                return ['type' => 'html', 'template' => true, 'data' => $epay->pagePay($params)];
            }
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * 统一下单方法
     * @throws Exception
     */
    static private function api_unified($type, $items, $ext_params = [])
    {
        $order = $items['order'];
        $buyer = $items['buyer'];

        $params = [
            "method"       => 'web',
            "type"         => $type,
            "device"       => self::getDevice(),
            "clientip"     => $buyer['ip'],
            "notify_url"   => $items['notify_url'],
            "return_url"   => $items['return_url'],
            "out_trade_no" => $order['trade_no'],
            "name"         => $items['subject'],
            "money"        => $order['buyer_pay_amount'],
        ];
        $params = array_merge($params, $ext_params);

        $epay = new EpayCore($items['channel']);

        return self::lockPaymentExt($order['trade_no'], function () use ($epay, $params) {
            $result = $epay->apiPay($params);
            return [$result['pay_type'], $result['pay_info']];
        });
    }

    /**
     * 支付宝
     * @param array $items
     * @return array
     */
    static public function alipay(array $items): array
    {
        // 支付宝用户授权及风控校验（使用公共授权账户模式）
        if (sys_config('payment', 'alipay_get_user_info_qrcode', 'off') === 'on') {
            $oauthResult = self::handleAlipayOauthAndRisk([], $items['order']);
            if ($oauthResult['mode'] === 'return') {
                return $oauthResult['data'];
            }
        }

        $buyer      = $items['buyer'];
        $ext_params = [];
        if ($buyer['cert_type'] === 'IDENTITY_CARD') {
            $ext_params = [
                'cert_no'   => $buyer['cert_no'],
                'cert_name' => $buyer['real_name'],
                'min_age'   => $buyer['min_age']
            ];
        }

        try {
            [$method, $url] = self::api_unified('alipay', $items, $ext_params);
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'redirect', 'url' => $url];
        } elseif ($method === 'html') {
            return ['type' => 'html', 'template' => true, 'data' => $url];
        }
        return ['type' => 'page', 'page' => 'alipay_qrcode', 'data' => ['url' => $url]];
    }

    /**
     * 微信支付
     * @param array $items
     * @return array
     */
    static public function wxpay(array $items): array
    {
        try {
            [$method, $url] = self::api_unified('wxpay', $items);
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'redirect', 'url' => $url];
        } elseif ($method === 'html') {
            return ['type' => 'html', 'template' => true, 'data' => $url];
        } elseif ($method === 'urlscheme') {
            // return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => $url];
            return ['type' => 'error', 'message' => '很抱歉，暂未适配urlscheme'];
        }

        if (isWechat()) {
            return ['type' => 'redirect', 'url' => $url];
        } elseif (isMobile()) {
            return ['type' => 'qrcode', 'page' => 'wxpay_wap', 'data' => ['url' => $url]];
        }
        return ['type' => 'qrcode', 'page' => 'wxpay_qrcode', 'data' => ['url' => $url]];
    }

    /**
     * QQ钱包
     * @param array $items
     * @return array
     */
    static public function qqpay(array $items): array
    {
        try {
            [$method, $url] = self::api_unified('qqpay', $items);
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'redirect', 'url' => $url];
        } elseif ($method === 'html') {
            return ['type' => 'html', 'data' => $url];
        }

        if (isQQ()) {
            return ['type' => 'redirect', 'url' => $url];
        } elseif (isMobile()) {
            return ['type' => 'qrcode', 'page' => 'qqpay_wap', 'data' => ['url' => $url]];
        }
        return ['type' => 'qrcode', 'page' => 'qqpay_qrcode', 'data' => ['url' => $url]];
    }

    /**
     * 云闪付/银联
     * @param array $items
     * @return array
     */
    static public function bank(array $items): array
    {
        try {
            [$method, $url] = self::api_unified('bank', $items);
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'redirect', 'url' => $url];
        } elseif ($method === 'html') {
            return ['type' => 'html', 'data' => $url];
        }
        return ['type' => 'qrcode', 'page' => 'bank_qrcode', 'data' => ['url' => $url]];
    }

    /**
     * 京东支付
     * @param array $items
     * @return array
     */
    static public function jdpay(array $items): array
    {
        try {
            [$method, $url] = self::api_unified('jdpay', $items);
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }

        if ($method === 'jump') {
            return ['type' => 'redirect', 'url' => $url];
        } elseif ($method === 'html') {
            return ['type' => 'html', 'data' => $url];
        }
        return ['type' => 'qrcode', 'page' => 'jdpay_qrcode', 'data' => ['url' => $url]];
    }

    /**
     * 异步通知处理
     * @param array $items
     * @return array
     */
    public static function notify(array $items): array
    {
        $order = $items['order'];
        $get   = request()->get();

        try {
            $epayNotify    = new EpayCore($items['channel']);
            $verify_result = $epayNotify->verify($get);

            if ($verify_result) {
                if ($get['trade_status'] === 'TRADE_SUCCESS') {
                    if ($get['out_trade_no'] === $order['trade_no'] && bccomp($get['total_amount'], $order['buyer_pay_amount'], 2) === 0) {
                        $buyer = [
                            'buyer_open_id' => empty($get['buyer']) ? null : $get['buyer'],
                        ];
                        self::processNotify(trade_no: $order['trade_no'], api_trade_no: $get['trade_no'], payment_time: $get['endtime'], buyer: $buyer);
                    }
                }
                return ['type' => 'html', 'data' => 'success'];
            }
        } catch (Throwable $e) {
            return ['type' => 'html', 'data' => 'fail: ' . $e->getMessage()];
        }
        return ['type' => 'html', 'data' => 'fail'];
    }

    /**
     * 同步通知处理
     * @param array $items
     * @return array
     */
    static public function return(array $items): array
    {
        $order = $items['order'];
        $get   = request()->get();

        try {
            $epayNotify    = new EpayCore($items['channel']);
            $verify_result = $epayNotify->verify($get);

            if ($verify_result) {
                if ($get['trade_status'] === 'TRADE_SUCCESS') {
                    if ($get['out_trade_no'] === $order['trade_no'] && bccomp($get['total_amount'], $order['buyer_pay_amount'], 2) === 0) {
                        return ['type' => 'location', 'url' => self::returnRedirectUrl($order)];
                    } else {
                        return ['type' => 'error', 'message' => '订单信息校验失败'];
                    }
                }
                return ['type' => 'error', 'message' => 'trade_status=' . $_GET['trade_status']];
            }
        } catch (Throwable $e) {
            return ['type' => 'error', 'message' => '验证失败: ' . $e->getMessage()];
        }
        return ['type' => 'error', 'message' => '验证失败！'];
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
        try {
            $epay = new EpayCore($channel);

            $params = [
                'trade_no'      => $order['api_trade_no'],
                'out_trade_no'  => $order['trade_no'],
                'money'         => $refund_record['amount'],
                'out_refund_no' => $refund_record['id']
            ];
            $result = $epay->execute('api/pay/refund', $params);
        } catch (Throwable $e) {
            return ['state' => false, 'message' => $e->getMessage()];
        }
        return ['state' => true, 'api_refund_no' => $result['trade_no'], 'refund_fee' => $result['money']];
    }

    /**
     * 交易关闭
     * @param array $order
     * @param array $channel
     * @return array
     */
    public static function close(array $order, array $channel): array
    {
        try {
            $epay = new EpayCore($channel);

            $params = [
                'trade_no'     => $order['api_trade_no'],
                'out_trade_no' => $order['trade_no']
            ];
            $epay->execute('api/pay/close', $params);
        } catch (Throwable $e) {
            return ['state' => false, 'message' => $e->getMessage()];
        }
        return ['state' => true, 'message' => '该订单已手动关闭'];
    }

    /**
     * 映射支付方式
     * @throws Exception
     */
    static private function getPayType($type): string
    {
        return match ($type) {
            'Alipay' => 'alipay',
            'WechatPay' => 'wxpay',
            'QQWallet' => 'qqpay',
            'Bank', 'UnionPay' => 'bank',
            'JDPay' => 'jdpay',
            'PayPal' => 'paypal',
            default => throw new Exception('不支持的支付方式: ' . $type),
        };
    }

    /**
     * 获取设备类型
     */
    static private function getDevice(): string
    {
        if (isWechat()) {
            $device = 'wechat';
        } elseif (isQQ()) {
            $device = 'qq';
        } elseif (isAlipay()) {
            $device = 'alipay';
        } elseif (isMobile()) {
            $device = 'mobile';
        } else {
            $device = 'pc';
        }
        return $device;
    }
}
