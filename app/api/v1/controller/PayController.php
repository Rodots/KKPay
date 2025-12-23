<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\Order;
use Carbon\Carbon;
use Core\Exception\PaymentException;
use Core\Service\PaymentService;
use Core\Service\OrderCreationService;
use Core\Service\RiskService;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 支付控制器
 * 处理商户支付请求
 */
class PayController extends BaseApiController
{
    /**
     * 页面跳转支付接口
     * 支持GET/POST表单，用于生成用户支付跳转链接
     */
    public function submit(Request $request): Response
    {
        // 解析业务参数(允许使用默认值)
        $bizContent = $this->parsePayBizContent($request, true);
        if (is_string($bizContent)) {
            return $this->pageMsg($bizContent);
        }

        // 风控检查
        if (RiskService::checkIpBlacklist($bizContent['buyer']['ip'], $request->merchant->id)) {
            return $this->pageMsg('系统异常，无法完成付款');
        }

        try {
            // 验证业务参数
            $validationResult = $this->validateBizContent($bizContent);
            if ($validationResult !== true) {
                return $this->pageMsg($validationResult);
            }

            // 创建订单
            [$order, $paymentChannelAccount, $orderBuyer] = OrderCreationService::createOrder($bizContent, $request->merchant);

            // 如果没有指定支付方式，跳转到收银台
            if ($paymentChannelAccount === null) {
                return redirect("/checkout/$order->trade_no.html");
            }

            // 发起支付
            $order         = $order->toArray();
            $paymentResult = PaymentService::initiatePayment($order, $paymentChannelAccount, $orderBuyer);
            // 处理网关支付数据
            return PaymentService::echoSubmit($paymentResult, $order);
        } catch (PaymentException $e) {
            Log::warning('[页面跳转支付接口]失败:' . $e->getMessage());
            return $this->pageMsg($e->getMessage());
        } catch (Throwable $e) {
            Log::error('[页面跳转支付接口]异常:' . $e->getMessage());
            return $this->pageMsg('系统异常，请稍后重试');
        }
    }

    /**
     * 统一收单交易支付接口
     * 仅接收POST JSON请求，返回JSON格式响应
     *
     * @param Request $request 请求对象（包含中间件注入的 merchant 和 verifiedParams）
     * @return Response JSON格式响应
     */
    public function create(Request $request): Response
    {
        // 解析业务参数(严格模式,不使用默认值)
        $bizContent = $this->parsePayBizContent($request, false);
        if (is_string($bizContent)) {
            return $this->fail($bizContent);
        }

        // 风控检查
        if (RiskService::checkIpBlacklist($bizContent['buyer']['ip'], $request->merchant->id)) {
            return $this->fail('系统异常，无法完成付款');
        }

        try {
            // 验证业务参数
            $validationResult = $this->validateBizContent($bizContent, true);
            if ($validationResult !== true) {
                return $this->fail($validationResult);
            }

            // 创建订单
            [$order, $paymentChannelAccount, $orderBuyer] = OrderCreationService::createOrder($bizContent, $request->merchant);

            // 发起支付
            $order         = $order->toArray();
            $paymentResult = PaymentService::initiatePayment($order, $paymentChannelAccount, $orderBuyer);
            // 处理网关支付数据
            $echoJson = PaymentService::echoJson($paymentResult, $order);
            return $this->success($echoJson);
        } catch (PaymentException $e) {
            Log::warning('[统一收单交易支付接口]失败:' . $e->getMessage());
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::error('[统一收单交易支付接口]异常:' . $e->getMessage());
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 解析支付业务参数
     *
     * @param Request $request     请求对象
     * @param bool    $useDefaults 是否为buyer信息使用默认值
     * @return array|string 解析后的参数数组,或错误消息字符串
     */
    private function parsePayBizContent(Request $request, bool $useDefaults = false): array|string
    {
        // 使用父类统一解析
        $data = $this->parseBizContent($request);
        if (is_string($data)) {
            return $data;
        }

        // 提取 buyer 数组,防 null 访问
        $buyerData = isset($data['buyer']) && is_array($data['buyer']) ? $data['buyer'] : [];

        // 构建参数数组
        return [
            'sign_type'            => $request->verifiedParams['sign_type'],
            'out_trade_no'         => $this->getString($data, 'out_trade_no'),
            'total_amount'         => $this->getAmount($data, 'total_amount'),
            'subject'              => $this->getString($data, 'subject'),
            'notify_url'           => $this->getString($data, 'notify_url'),
            'return_url'           => $this->getString($data, 'return_url'),
            'payment_type'         => $this->getString($data, 'payment_type'),
            'payment_channel_code' => $this->getString($data, 'payment_channel_code'),
            'attach'               => $this->getString($data, 'attach'),
            'quit_url'             => $this->getString($data, 'quit_url'),
            'close_time'           => $this->getString($data, 'close_time'),
            'buyer'                => $this->parseBuyerData($buyerData, $request, $useDefaults),
        ];
    }

    /**
     * 解析买家信息
     *
     * @param array   $buyerData   买家数据
     * @param Request $request     请求对象
     * @param bool    $useDefaults 是否使用默认值
     * @return array 解析后的买家信息
     */
    private function parseBuyerData(array $buyerData, Request $request, bool $useDefaults): array
    {
        return [
            'real_name'  => $this->getString($buyerData, 'real_name'),
            'cert_no'    => $this->getString($buyerData, 'cert_no'),
            'cert_type'  => $this->getString($buyerData, 'cert_type'),
            'min_age'    => $this->getString($buyerData, 'min_age'),
            'mobile'     => $this->getString($buyerData, 'mobile'),
            'ip'         => $useDefaults ? $request->getRealIp() : $this->getString($buyerData, 'ip'),
            'user_agent' => $useDefaults ? $request->header('user-agent') : $this->getString($buyerData, 'user_agent'),
        ];
    }

    /**
     * 验证业务参数
     *
     * @param array $bizContent   业务参数
     * @param bool  $isStrictMode 是否为严格验证模式(create接口为true,submit接口为false)
     * @return string|true 验证通过返回true,失败返回错误消息
     */
    private function validateBizContent(array $bizContent, bool $isStrictMode = false): string|true
    {
        // 验证商户订单号
        $outTradeNo = $this->filterString($bizContent['out_trade_no'] ?? null);
        if (empty($outTradeNo)) {
            return '商户订单号(out_trade_no)缺失';
        }
        if (strlen($outTradeNo) > 128) {
            return '商户订单号(out_trade_no)长度不能超过128个字符';
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $outTradeNo)) {
            return '商户订单号(out_trade_no)格式错误，只能包含英文字母、数字、下划线和横线';
        }

        // 验证订单金额(最少1分钱，最多1亿)
        if (bccomp($bizContent['total_amount'], '0.01', 2) < 0 || bccomp($bizContent['total_amount'], '100000000', 2) > 0) {
            return '订单金额(total_amount)不规范';
        }

        // 验证订单标题
        $subject = $this->filterString($bizContent['subject'] ?? null);
        if (empty($subject)) {
            return '订单标题(subject)缺失';
        }
        if (strlen($subject) > 255) {
            return '订单标题(subject)长度不能超过255个字符';
        }

        // 验证异步通知地址
        $notifyUrl = $this->filterString($bizContent['notify_url'] ?? null);
        if (empty($notifyUrl) || !filter_var($notifyUrl, FILTER_VALIDATE_URL)) {
            return '异步通知地址(notify_url)格式错误';
        }

        // 验证同步通知地址
        $returnUrl = $this->filterString($bizContent['return_url'] ?? null);
        if (empty($returnUrl) || !filter_var($returnUrl, FILTER_VALIDATE_URL)) {
            return '同步通知地址(return_url)格式错误';
        }

        // 验证支付类型
        $paymentType = $this->filterString($bizContent['payment_type'] ?? null);
        if ($isStrictMode && empty($paymentType)) {
            return '支付类型(payment_type)缺失';
        }
        if ($paymentType && !Order::checkPaymentType($paymentType)) {
            return '支付类型(payment_type)不被允许';
        }

        // 如果传了payment_channel_code但没传payment_type，需要拦截
        $paymentChannelCode = $this->filterString($bizContent['payment_channel_code'] ?? null);
        if (!empty($paymentChannelCode) && empty($paymentType)) {
            return '指定支付通道编码(payment_channel_code)时必须同时指定支付方式(payment_type)';
        }

        // 验证附加参数
        $attach = $this->filterString($bizContent['attach'] ?? null);
        if ($attach && strlen($attach) > 128) {
            return '附加参数(attach)长度不能超过128个字符';
        }

        // 验证中途退出地址
        $quitUrl = $this->filterString($bizContent['quit_url'] ?? null);
        if ($quitUrl && (strlen($quitUrl) > 400 || !filter_var($quitUrl, FILTER_VALIDATE_URL))) {
            return '中途退出地址(quit_url)格式错误';
        }

        // 验证买家IP
        $buyerIp = $this->filterString($bizContent['buyer']['ip'] ?? null);
        if ($isStrictMode && empty($buyerIp)) {
            return '买家IP(buyer.ip)缺失';
        }

        // 校验订单关闭时间
        if (!empty($bizContent['close_time'])) {
            try {
                $timezone = config('app.default_timezone');
                $now      = Carbon::now()->timezone($timezone);

                // 解析关闭时间
                $closeTimeInput = $bizContent['close_time'];
                // 使用 is_numeric 检查，避免冗余 filter
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
                    return "订单关闭时间(close_time)过早，最早可设置为 {$earliestCloseTime->format('Y-m-d H:i:s')}（当前时间+1分钟）";
                }

                // 校验：不能晚于当前时间 + 24 小时
                if ($closeTime->gt($latestCloseTime)) {
                    return "订单关闭时间(close_time)过晚，最晚可设置为 {$latestCloseTime->format('Y-m-d H:i:s')}（当前时间+24小时）";
                }
            } catch (Throwable) {
                return '订单关闭时间格式无效，请使用有效的时间戳或标准时间格式（如 "2026-01-01 01:01:01"）';
            }
        }

        return true;
    }

    /**
     * 过滤字符串参数
     *
     * 对字符串进行安全过滤,去除HTML/PHP标签
     * 与getString不同,此方法专注于过滤而不是取值
     *
     * @param string|null $value 待过滤的值
     * @return string|null 过滤后的值,null保持为null
     */
    private function filterString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // 去除两端空格
        $filtered = trim($value);

        // 过滤HTML和PHP标签
        $filtered = strip_tags($filtered);

        // 如果过滤后为空字符串,返回null
        return $filtered === '' ? null : $filtered;
    }
}
