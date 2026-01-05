<?php

declare(strict_types=1);

namespace Core\Service;

use app\model\OrderBuyer;
use app\model\PaymentChannelAccount;
use Core\Exception\PaymentException;
use Core\Utils\PaymentGatewayUtil;
use support\Log;
use support\Response;
use Throwable;

/**
 * 支付服务类
 * 封装核心支付业务逻辑
 */
class PaymentService
{
    // 接口类型枚举
    const string METHOD_WEB         = 'web';         // 通用网页支付（根据device自动返回跳转url/二维码/小程序跳转url等）
    const string METHOD_REDIRECT    = 'redirect';    // 跳转支付（仅返回跳转url）
    const string METHOD_JSAPI       = 'jsapi';       // JSAPI支付（小程序内支付，需传入sub_openid和sub_appid）
    const string METHOD_APP         = 'app';         // APP支付（iOS/安卓APP内支付）
    const string METHOD_SCAN        = 'scan';        // 付款码支付（需传入auth_code）
    const string METHOD_MINIPROGRAM = 'miniprogram'; // 小程序支付（返回小程序插件参数或跳转小程序参数）

    /**
     * 检查传入的接口类型是否合法
     */
    public static function checkMethod(?string $method): bool
    {
        if ($method === null) {
            return true;
        }

        return in_array($method, [
            self::METHOD_WEB,
            self::METHOD_REDIRECT,
            self::METHOD_JSAPI,
            self::METHOD_APP,
            self::METHOD_SCAN,
            self::METHOD_MINIPROGRAM,
        ]);
    }

    /**
     * 发起支付
     *
     * @param array                 $order                 订单数据
     * @param PaymentChannelAccount $paymentChannelAccount 支付通道账户
     * @param OrderBuyer            $orderBuyer            订单买家
     * @param string                $mode                  支付模式
     * @param string                $method                接口类型
     * @param array                 $extraParams           接口类型相关的额外参数
     * @return array
     * @throws PaymentException
     */
    public static function initiatePayment(array $order, PaymentChannelAccount $paymentChannelAccount, OrderBuyer $orderBuyer, string $mode = 'page', string $method = 'web', array $extraParams = []): array
    {
        try {
            // 获取支付通道信息
            if (!$paymentChannel = $paymentChannelAccount->paymentChannel) {
                throw new PaymentException('支付通道信息缺失');
            }

            // 处理商品名称优先级（5级：子账户 > 通道 > 商户 > 全局配置 > 原始请求）
            $merchant = request()->merchant ?? null;
            $subject  = self::resolveOrderSubject($order['subject'], $order['trade_no'], $order['out_trade_no'], $paymentChannelAccount, $paymentChannel, $merchant);

            $notify_url = sys_config('system', 'notify_url');

            $items = [
                'isExtension' => false,
                'order'       => $order,
                'channel'     => $paymentChannelAccount->config,
                'buyer'       => $orderBuyer->toArray(),
                'subject'     => $subject,
                'method'      => $method,
                'return_url'  => site_url("pay/return/{$order['trade_no']}.html"),
                'notify_url'  => empty($notify_url) ? site_url("pay/notify/{$order['trade_no']}.html") : $notify_url . "pay/notify/{$order['trade_no']}.html",
            ];

            // 合并接口类型相关的额外参数
            if (!empty($extraParams)) {
                $items = array_merge($items, $extraParams);
            }

            // 加载网关
            return PaymentGatewayUtil::loadGateway($paymentChannel->gateway, $mode, $items);
        } catch (Throwable $e) {
            Log::error('支付发起失败，调用网关失败', [
                'trade_no'    => $order['trade_no'],
                'error'       => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);

            throw new PaymentException('支付发起失败');
        }
    }

    /**
     * 响应提交结果（统一收单交易支付，只输出JSON）
     *
     * @throws PaymentException
     */
    public static function echoJson(array $result, array $order): array
    {
        $type = $result['type'] ?? '';
        if (!$type) {
            throw new PaymentException('支付网关返回了未知的处理类型');
        }
        switch ($type) {
            case 'redirect': //跳转
                $json['pay_type'] = 'redirect';
                $json['pay_info'] = $result['url'];
                break;
            case 'html': //显示HTML
                $json['pay_type'] = 'html';
                $json['pay_info'] = isset($result['data']) ? (is_array($result['data']) ? json_encode($result['data']) : $result['data']) : '';
                break;
            case 'json': //显示JSON
                $json['pay_type'] = 'json';
                $json['pay_info'] = $result['data'] ?? [];
                break;
            case 'page': //显示指定页面
                $page = $result['page'] ?? '404';
                if (strpos($page, 'qrcode')) {
                    $json['pay_type'] = 'qrcode';
                    $json['pay_info'] = $result['data']['url'];
                } else {
                    $json['pay_type'] = 'redirect';
                    $json['pay_info'] = site_url("pay/submit/{$order['trade_no']}.html");
                }
                break;
            case 'error': //错误提示
                throw new PaymentException($result['message'] ?? '未知错误');
            default:
                $json['pay_type'] = 'redirect';
                $json['pay_info'] = site_url("pay/submit/{$order['trade_no']}.html");
                break;
        }
        return $json;
    }

    /**
     * 响应提交结果（页面跳转支付，默认输出可视化界面）
     *
     * @throws PaymentException
     */
    public static function echoPage(array $result, array $order): Response
    {
        $type = $result['type'] ?? '';
        if (!$type) {
            throw new PaymentException('支付网关返回了未知的处理类型');
        }
        switch ($type) {
            case 'redirect': //跳转
                $url       = htmlspecialchars($result['url'] ?? '', ENT_QUOTES, 'UTF-8');
                $html_text = '<script>window.location.replace(\'' . $url . '\');</script>';
                return self::redirectTemplate($html_text);
            case 'html': //显示HTML
                $html_text = $result['data'] ?? '';
                if (isset($result['template']) && $result['template'] && str_starts_with($html_text, '<form ')) {
                    return self::redirectTemplate($html_text);
                }
                return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache'], is_array($html_text) ? json_encode($html_text) : $html_text);
            case 'json': //显示JSON
                return json($result['data'] ?? []);
            case 'page': //显示指定页面
                $page = $result['page'] ?? '404';
                try {
                    return raw_view("/app/api/view/pay_page/$page", array_merge($result['data'] ?? [], ['order' => $order]));
                } catch (Throwable $e) {
                    Log::error($e->getTraceAsString());
                    throw new PaymentException("页面不存在: $page");
                }
            case 'error': //错误提示
            default:
                throw new PaymentException($result['message'] ?? '未知错误');
        }
    }

    /**
     * 跳转页面模板
     *
     * @param string $html_text
     *
     * @return Response
     */
    private static function redirectTemplate(string $html_text): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>正在为您跳转到支付页面，请稍候...</title>
    <style>
        .loader {
          width: fit-content;
          font-weight: bold;
          font-family: sans-serif;
          font-size: 30px;
          padding: 0 5px 8px 0;
          background: repeating-linear-gradient(90deg,currentColor 0 8%,#0000 0 10%) 200% 100%/200% 3px no-repeat;
          animation: l3 1.5s steps(6) infinite;
        }
        .loader:before {
          content:"正在为您跳转到支付页面，请稍候..."
        }
        @keyframes l3 {to{background-position: 80% 100%}}
    </style>
</head>
<body>
    <div class="loader"></div>
    {$html_text}
</body>
</html>
HTML;
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache'], $html);
    }

    /**
     * 解析商品名称（5级优先级）
     *
     * 优先级从高到低：
     * 1. 通道子账户自定义商品名
     * 2. 通道自定义商品名
     * 3. 商户自定义商品名
     * 4. 全局系统配置自定义商品名
     * 5. 商户原始请求商品名（默认）
     *
     * @param string                           $originalSubject       原始商品名
     * @param string                           $tradeNo               平台订单号
     * @param string                           $outTradeNo            商户订单号
     * @param \app\model\PaymentChannelAccount $paymentChannelAccount 通道子账户
     * @param \app\model\PaymentChannel        $paymentChannel        支付通道
     * @param \app\model\Merchant|null         $merchant              商户
     * @return string
     */
    private static function resolveOrderSubject(string $originalSubject, string $tradeNo, string $outTradeNo, \app\model\PaymentChannelAccount $paymentChannelAccount, \app\model\PaymentChannel $paymentChannel, ?\app\model\Merchant $merchant): string
    {
        // 获取各级配置的自定义商品名
        $accountSubject  = $paymentChannelAccount->diy_order_subject ?? null;
        $channelSubject  = $paymentChannel->diy_order_subject ?? null;
        $merchantSubject = $merchant?->diy_order_subject;
        $globalSubject   = sys_config('payment', 'diy_order_subject');

        // 按优先级选择第一个非空值
        $template = $accountSubject ?: ($channelSubject ?: ($merchantSubject ?: ($globalSubject ?: null)));

        // 如果没有配置任何自定义商品名，直接返回原始商品名
        if (empty($template)) {
            return $originalSubject;
        }

        // 应用变量替换
        return self::processOrderSubject($template, $originalSubject, $tradeNo, $outTradeNo, $merchant?->email, $merchant?->mobile);
    }

    /**
     * 处理自定义商品名称变量替换
     *
     * 支持的变量：
     * - [name]     原商品名称
     * - [order]    平台订单号 (trade_no)
     * - [outorder] 商户订单号 (out_trade_no)
     * - [time]     11位时间戳
     * - [email]    商户联系邮箱
     * - [mobile]   商户手机号
     *
     * @param string      $template   模板字符串
     * @param string      $name       原商品名称
     * @param string      $tradeNo    平台订单号
     * @param string      $outTradeNo 商户订单号
     * @param string|null $email      商户邮箱
     * @param string|null $mobile     商户手机号
     * @return string
     */
    private static function processOrderSubject(string $template, string $name, string $tradeNo, string $outTradeNo, ?string $email, ?string $mobile): string
    {
        return str_replace(
            ['[name]', '[order]', '[outorder]', '[time]', '[email]', '[mobile]'],
            [$name, $tradeNo, $outTradeNo, (string)time(), $email ?? '', $mobile ?? ''],
            $template
        );
    }
}
