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
    /**
     * 发起支付
     *
     * @throws PaymentException
     */
    public static function initiatePayment(array $order, PaymentChannelAccount $paymentChannelAccount, OrderBuyer $orderBuyer, string $mode = 'submit'): array
    {
        try {
            // 获取支付通道信息
            $paymentChannel = $paymentChannelAccount->paymentChannel;
            if (!$paymentChannel) {
                throw new PaymentException('支付通道信息缺失');
            }

            // 处理自定义商品名称变量替换
            $subject  = $order['subject'];
            $merchant = request()->merchant ?? null;
            if ($merchant && !empty($merchant->diy_order_subject)) {
                $subject = self::processOrderSubject($merchant->diy_order_subject, $order['subject'], $order['trade_no'], $order['out_trade_no'], $merchant->email, $merchant->mobile);
            }

            $items = [
                'order'      => $order,
                'channel'    => $paymentChannelAccount->config,
                'buyer'      => $orderBuyer->toArray(),
                'subject'    => $subject,
                'return_url' => site_url("pay/return/{$order['trade_no']}.html"),
                'notify_url' => site_url("pay/notify/{$order['trade_no']}.html"),
            ];

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
     * 响应提交结果
     *
     * @throws PaymentException
     */
    public static function echoSubmit(array $result, array $order): Response
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
                } else {
                    return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache'], $html_text);
                }
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
     * 响应提交结果
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
                $json['pay_info'] = $result['data'] ?? '';
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
