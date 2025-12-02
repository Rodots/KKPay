<?php

declare(strict_types = 1);

namespace app\api\v1\controller;

use app\api\v1\middleware\GetSignatureVerification;
use app\model\Order;
use Carbon\Carbon;
use Core\Exception\PaymentException;
use Core\Service\OrderService;
use Core\Service\PaymentService;
use Core\Service\OrderCreationService;
use Core\Traits\ApiResponse;
use Core\Utils\PaymentGatewayUtil;
use support\annotation\Middleware;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 支付控制器
 * 处理商户支付请求
 */
class PayController
{
    use ApiResponse;

    /**
     * 页面跳转支付接口
     * 支持GET/POST表单，用于生成用户支付跳转链接
     */
    #[Middleware(GetSignatureVerification::class)]
    public function submit(Request $request): Response
    {
        try {
            // 中间件已经验证了签名和商户信息
            $merchant = $request->merchant;
            $params   = $request->verifiedParams;

            // 解析业务参数
            $bizContent = $this->parseBizContent($params['biz_content']);
            if (is_string($bizContent)) {
                return $this->pageMsg($bizContent);
            }

            // 验证业务参数
            $validationResult = $this->validateBizContent($bizContent);
            if ($validationResult !== true) {
                return $this->pageMsg($validationResult);
            }

            // 创建订单
            [$order, $paymentChannelAccount, $orderBuyer] = OrderCreationService::createOrder($bizContent, $merchant, $request->getRealIp());
            $order = $order->toArray();

            // 如果没有指定支付方式，跳转到收银台
            if ($paymentChannelAccount === null) {
                return redirect('/checkout/' . $order['trade_no']);
            }

            // 发起支付
            $paymentResult = PaymentService::initiatePayment($order, $paymentChannelAccount, $orderBuyer);
            // 处理网关支付数据
            return PaymentService::echoSubmit($paymentResult, $order);
        } catch (PaymentException $e) {
            Log::warning('支付页面跳转失败:' . $e->getMessage());
            return $this->pageMsg($e->getMessage());
        } catch (Throwable $e) {
            Log::error('支付页面跳转异常:' . $e->getMessage());
            return $this->pageMsg('系统异常，请稍后重试');
        }
    }

    /**
     * JSON支付接口
     * 仅接收POST JSON请求，返回JSON格式响应
     */
    public function create(Request $request): Response
    {
        return $this->pageMsg('待完善');
    }

    /**
     * 解析业务参数
     */
    private function parseBizContent(string $bizContent): array|string
    {
        $decoded = base64_decode($bizContent, true);
        if ($decoded === false) {
            return '业务参数base64解码失败';
        }

        if (!json_validate($decoded)) {
            return '业务参数JSON格式错误';
        }

        $data = json_decode($decoded, true);

        return [
            'out_trade_no'         => filter((string)$data['out_trade_no'] ?? null),
            'total_amount'         => (float)($data['total_amount'] ?? 0),
            'subject'              => filter((string)$data['subject'] ?? null),
            'notify_url'           => filter((string)$data['notify_url'] ?? null),
            'return_url'           => filter((string)$data['return_url'] ?? null),
            'payment_type'         => !empty($data['payment_type']) ? filter((string)$data['payment_type']) : null,
            'payment_channel_code' => !empty($data['payment_channel_code']) ? filter((string)$data['payment_channel_code']) : null,
            'attach'               => !empty($data['attach']) ? filter((string)$data['attach']) : null,
            'quit_url'             => !empty($data['quit_url']) ? filter((string)$data['quit_url']) : null,
            'close_time'           => !empty($data['close_time']) ? (is_numeric($data['close_time']) ? (int)$data['close_time'] : filter($data['close_time'])) : null,
            'buyer'                => [
                'phone'      => !empty($data['buyer']['phone']) ? filter((string)$data['buyer']['phone']) : null,
                'ip'         => !empty($data['buyer']['ip']) ? filter((string)$data['buyer']['ip']) : null,
                'user_agent' => !empty($data['buyer']['user_agent']) ? filter((string)$data['buyer']['user_agent']) : null,
            ]
        ];
    }

    /**
     * 验证业务参数
     */
    private function validateBizContent(array $bizContent): string|true
    {
        if (empty($bizContent['out_trade_no'])) {
            return '商户订单号(out_trade_no)缺失';
        }
        if (strlen($bizContent['out_trade_no']) > 128) {
            return '商户订单号(out_trade_no)长度不能超过128个字符';
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $bizContent['out_trade_no'])) {
            return '商户订单号(out_trade_no)格式错误，只能包含字母、数字、下划线和横线';
        }

        // 最少1分钱，最多1亿
        if ($bizContent['total_amount'] <= 0 || $bizContent['total_amount'] > 100000000) {
            return '订单金额(total_amount)不规范';
        }

        if (empty($bizContent['subject'])) {
            return '订单标题(subject)缺失';
        }
        if (strlen($bizContent['subject']) > 255) {
            return '订单标题(subject)长度不能超过255个字符';
        }

        if (empty($bizContent['notify_url']) || !filter_var($bizContent['notify_url'], FILTER_VALIDATE_URL)) {
            return '异步通知地址(notify_url)格式错误';
        }

        if (empty($bizContent['return_url']) || !filter_var($bizContent['return_url'], FILTER_VALIDATE_URL)) {
            return '同步通知地址(return_url)格式错误';
        }

        if ($bizContent['payment_type'] && !Order::checkPaymentType($bizContent['payment_type'])) {
            return '支付类型(payment_type)不被允许';
        }

        // 如果传了payment_channel_code但没传payment_type，需要拦截
        if (!empty($bizContent['payment_channel_code']) && empty($bizContent['payment_type'])) {
            return '指定支付通道编码(payment_channel_code)时必须同时指定支付方式(payment_type)';
        }

        if (!empty($bizContent['attach']) && strlen($bizContent['attach']) > 128) {
            return '附加参数(attach)长度不能超过128个字符';
        }

        if (!empty($bizContent['quit_url']) && (strlen($bizContent['quit_url']) > 400 || !filter_var($bizContent['quit_url'], FILTER_VALIDATE_URL))) {
            return '中途退出地址(quit_url)格式错误';
        }

        // 校验订单关闭时间
        if (!empty($bizContent['close_time'])) {
            try {
                $timezone = config('app.default_timezone');
                $now      = Carbon::now()->timezone($timezone);

                // 解析关闭时间
                $closeTimeInput = $bizContent['close_time'];
                if (is_numeric($closeTimeInput)) {
                    $closeTime = Carbon::createFromTimestamp((int)$closeTimeInput)->timezone($timezone);
                } else {
                    $closeTime = Carbon::parse($closeTimeInput)->timezone($timezone);
                }

                // 定义有效时间窗口：[当前时间 + 1 分钟, 当前时间 + 24 小时]
                $earliestCloseTime = $now->copy()->addMinute();
                $latestCloseTime   = $now->copy()->addDay();

                // 校验：不能早于当前时间 + 1 分钟
                if ($closeTime->lt($earliestCloseTime)) {
                    // 润色后：明确指出最早时间
                    return "订单关闭时间(close_time)过早，最早可设置为 {$earliestCloseTime->format('Y-m-d H:i:s')}（当前时间+1分钟）";
                }

                // 校验：不能晚于当前时间 + 24 小时
                if ($closeTime->gt($latestCloseTime)) {
                    // 润色后：明确指出最晚时间
                    return "订单关闭时间(close_time)过晚，最晚可设置为 {$latestCloseTime->format('Y-m-d H:i:s')}（当前时间+24小时）";
                }
            } catch (Throwable) {
                return '订单关闭时间格式无效，请使用有效的时间戳或标准时间格式（如 "2026-01-01 01:01:01"）';
            }
        }

        return true;
    }

    /**
     * 处理网关扩展方法调用
     * 路由格式: /api/v1/pay/{method}/{orderNo}
     */
    public function handleExtensionMethod(Request $request): Response
    {
        $method  = $request->route->param('method');
        $orderNo = $request->route->param('orderNo');

        $echoMethod = ($method === 'notify') ? 'textMsg' : 'pageMsg';

        if (empty($method) || empty($orderNo)) {
            return $this->$echoMethod('参数错误');
        }

        if ($method === 'notify') {
            Log::channel('pay_notify')->info('orderNo: ' . $orderNo, $request->all());
        }

        try {
            // 预加载 paymentChannelAccount 及其关联的 paymentChannel（仅需 gateway 字段）
            $order = Order::with([
                'paymentChannelAccount' => function ($query) {
                    $query->select(['id', 'payment_channel_id', 'config'])->with('paymentChannel:id,gateway');
                }
            ])->where('trade_no', $orderNo)->first();

            if ($order === null) {
                return $this->$echoMethod('订单不存在');
            }

            if (!OrderService::canPay($order)) {
                return $this->$echoMethod('当前订单已交易结束');
            }

            $paymentChannelAccount = $order->paymentChannelAccount;
            if ($paymentChannelAccount === null) {
                return redirect("/checkout/$order->trade_no.html");
            }

            // 构建纯净订单数据
            $orderData = $order->toArray();
            unset($orderData['payment_channel_account']);

            $items = [
                'order'      => $orderData,
                'channel'    => $paymentChannelAccount->config,
                'buyer'      => $order->buyer->toArray(),
                'config'     => sys_config(),
                'subject'    => $order->subject,
                'return_url' => site_url("pay/return/$order->trade_no.html"),
                'notify_url' => site_url("pay/notify/$order->trade_no.html"),
            ];
            unset($order);

            $gateway = $paymentChannelAccount->paymentChannel->gateway;

            $paymentResult = PaymentGatewayUtil::loadGateway($gateway, $method, $items);

            return PaymentService::echoSubmit($paymentResult, $orderData);
        } catch (Throwable $e) {
            Log::error('支付拓展方法加载异常', [
                'method'   => $method,
                'order_no' => $orderNo,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return $this->$echoMethod('系统异常，请稍后重试');
        }
    }
}
