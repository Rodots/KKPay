<?php

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\Order;
use app\model\OrderBuyer;
use Carbon\Carbon;
use Core\baseController\ApiBase;
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
class PayController extends ApiBase
{
    /**
     * 统一收单交易支付接口
     * 仅接收POST JSON请求，返回JSON格式响应
     *
     * @param Request $request 请求对象（包含中间件注入的 merchant 和 verifiedParams）
     * @return Response JSON格式响应
     */
    public function index(Request $request): Response
    {
        // 解析业务参数
        $bizContent = $this->parsePayBizContent($request);
        if (is_string($bizContent)) {
            return $this->fail($bizContent);
        }

        // 综合风控检查
        if ($riskError = RiskService::checkCreateOrderRisk($request->merchant->id, $bizContent['buyer'])) {
            return $this->fail($riskError);
        }

        try {
            // 验证业务参数
            $validationResult = $this->validateBizContent($bizContent, true);
            if ($validationResult !== true) {
                return $this->fail($validationResult);
            }

            // 创建订单
            [$order, $paymentChannelAccount, $orderBuyer] = OrderCreationService::createOrder($bizContent, $request->merchant);

            // 获取接口类型
            $method = $bizContent['method'];
            $order  = $order->toArray();

            // 如果是 redirect 类型，直接返回跳转链接，不调用网关
            if ($method === 'redirect') {
                return $this->success([
                    'pay_type' => 'redirect',
                    'pay_info' => site_url("pay/page/{$order['trade_no']}.html"),
                ]);
            }

            // 构建额外参数（根据 method 类型）
            $extraParams = $this->buildMethodExtraParams($bizContent);

            // 发起支付
            $paymentResult = PaymentService::initiatePayment($order, $paymentChannelAccount, $orderBuyer, 'unified', $method, $extraParams);
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
     * 页面跳转支付接口
     * 支持GET/POST表单，用于生成用户支付跳转链接
     */
    public function page(Request $request): Response
    {
        // 解析业务参数
        $bizContent = $this->parsePayBizContent($request, true);
        if (is_string($bizContent)) {
            return $this->pageMsg($bizContent);
        }

        // 综合风控检查
        if ($riskError = RiskService::checkCreateOrderRisk($request->merchant->id, $bizContent['buyer'])) {
            return $this->pageMsg($riskError);
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
            $paymentResult = PaymentService::initiatePayment($order, $paymentChannelAccount, $orderBuyer, 'page', 'web');
            // 处理网关支付数据
            return PaymentService::echoPage($paymentResult, $order);
        } catch (PaymentException $e) {
            Log::warning('[页面跳转支付接口]失败:' . $e->getMessage());
            return $this->pageMsg($e->getMessage());
        } catch (Throwable $e) {
            Log::error('[页面跳转支付接口]异常:' . $e->getMessage());
            return $this->pageMsg('系统异常，请稍后重试');
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

        // 处理订单关闭时间
        $closeTime = $this->getString($data, 'close_time');
        if (empty($closeTime)) {
            $sysExpireTime = sys_config('payment', 'order_expire_time');
            if (is_numeric($sysExpireTime)) {
                $closeTime = time() + (int)$sysExpireTime;
            }
        }

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
            'close_time'           => $closeTime,
            'method'               => $useDefaults ? 'web' : $this->getString($data, 'method'),
            // method相关的额外参数
            'sub_openid'           => $useDefaults ? null : $this->getString($data, 'sub_openid'),
            'sub_appid'            => $useDefaults ? null : $this->getString($data, 'sub_appid'),
            'auth_code'            => $useDefaults ? null : $this->getString($data, 'auth_code'),
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
            'user_id'       => $this->getString($buyerData, 'user_id'),
            'buyer_open_id' => $this->getString($buyerData, 'buyer_open_id'),
            'real_name'     => $this->getString($buyerData, 'real_name'),
            'cert_no'       => $this->getString($buyerData, 'cert_no'),
            'cert_type'     => $this->getString($buyerData, 'cert_type'),
            'min_age'       => $this->getInt($buyerData, 'min_age'),
            'mobile'        => $this->getString($buyerData, 'mobile'),
            'ip'            => $useDefaults ? $request->getRealIp() : $this->getString($buyerData, 'ip'),
            'user_agent'    => $useDefaults ? $request->header('user-agent') : $this->getString($buyerData, 'user_agent'),
        ];
    }

    /**
     * 验证业务参数
     *
     * @param array $bizContent   业务参数
     * @param bool  $isStrictMode 是否为严格验证模式
     * @return string|true 验证通过返回true,失败返回错误消息
     */
    private function validateBizContent(array $bizContent, bool $isStrictMode = false): string|true
    {
        // 一次性获取整个 payment 配置组，避免多次Redis查询
        $paymentConfig = sys_config('payment');

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
        $configMin = $paymentConfig['min_amount'] ?? null;
        $configMax = $paymentConfig['max_amount'] ?? null;
        $minAmount = '0.01';
        $maxAmount = '100000000';
        if (!empty($configMin) && is_numeric($configMin) && bccomp($configMin, '0.01', 2) === 1) {
            $minAmount = $configMin;
        }
        if (!empty($configMax) && is_numeric($configMax) && bccomp($configMax, '0', 2) === 1 && bccomp($configMax, '100000000', 2) === -1) {
            $maxAmount = $configMax;
        }
        if (bccomp($bizContent['total_amount'], $minAmount, 2) < 0 || bccomp($bizContent['total_amount'], $maxAmount, 2) > 0) {
            return "订单金额(total_amount)超出允许范围[$minAmount-$maxAmount]";
        }

        // 验证商品名称
        $subject = $this->filterString($bizContent['subject'] ?? null);
        if (empty($subject)) {
            return '商品名称(subject)缺失';
        }
        if (mb_strlen($subject, 'UTF-8') > 255) {
            return '商品名称(subject)长度不能超过255个字符';
        }
        if (preg_match('/[\/=&]/', $subject)) {
            return '商品名称(subject)不可包含特殊字符（/、=、&）';
        }
        // 检查商品名称是否包含屏蔽关键词
        $blockedKeywords = $paymentConfig['subject_blocked_keywords'] ?? '';
        if (!empty($blockedKeywords)) {
            $keywords = array_filter(explode('|', $blockedKeywords));
            foreach ($keywords as $keyword) {
                if (mb_stripos($subject, trim($keyword)) !== false) {
                    return $paymentConfig['subject_blocked_keywords_prompt'] ?? '温馨提示：该商品禁止出售，如有疑问请联系网站客服！';
                }
            }
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

        // 验证接口类型（仅统一收单交易支付接口需要，且为必传参数）
        $method = $this->filterString($bizContent['method'] ?? null);
        if ($isStrictMode) {
            if (empty($method)) {
                return '接口类型(method)缺失';
            }
            if (!PaymentService::checkMethod($method)) {
                return '接口类型(method)不被允许，仅支持: web, redirect, jsapi, app, scan, miniprogram';
            }
            // 根据 method 类型验证额外必填参数
            if ($method === 'jsapi') {
                $subOpenid = $this->filterString($bizContent['sub_openid'] ?? null);
                $subAppid  = $this->filterString($bizContent['sub_appid'] ?? null);
                if (empty($subOpenid) || empty($subAppid)) {
                    return '接口类型为jsapi时需要传入用户OpenID(sub_openid)和公众账号ID(sub_appid)参数';
                }
            } elseif ($method === 'scan') {
                $authCode = $this->filterString($bizContent['auth_code'] ?? null);
                if (empty($authCode)) {
                    return '接口类型为scan时需要传入支付授权码(auth_code)参数';
                }
            }
        }

        // 验证买家IP
        $buyerIp = $this->filterString($bizContent['buyer']['ip'] ?? null);
        if ($isStrictMode && empty($buyerIp)) {
            return '买家IP(buyer.ip)缺失';
        }
        // 校验买家信息
        $buyerValidation = $this->validateBuyerInfo($bizContent['buyer'] ?? []);
        if ($buyerValidation !== true) {
            return $buyerValidation;
        }

        // 校验订单关闭时间
        if (!empty($bizContent['close_time'])) {
            try {
                $timezone = config('app.default_timezone');
                $now      = Carbon::now()->timezone($timezone)->setMicrosecond(0);

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
     * 验证买家信息
     *
     * @param array $buyer 买家信息数组
     * @return string|true 验证通过返回true,失败返回错误消息
     */
    private function validateBuyerInfo(array $buyer): string|true
    {
        // 校验真实姓名
        $realName = $this->filterString($buyer['real_name'] ?? null);
        if ($realName !== null) {
            $nameLen = mb_strlen($realName, 'UTF-8');
            if ($nameLen < 2 || $nameLen > 50) {
                return '买家真实姓名(buyer.real_name)长度必须在2-50个字符之间';
            }
        }

        // 校验证件类型
        $certType = $this->filterString($buyer['cert_type'] ?? null);
        if ($certType !== null && !OrderBuyer::isValidCertType($certType)) {
            return '买家证件类型(buyer.cert_type)不合法';
        }

        // 校验证件号码
        $certNo = $this->filterString($buyer['cert_no'] ?? null);
        if ($certNo !== null) {
            // 提供证件号码时必须同时提供证件类型
            if ($certType === null) {
                return '提供证件号码(buyer.cert_no)时必须同时指定证件类型(buyer.cert_type)';
            }
            // 根据证件类型进行格式校验
            $certValidation = $this->validateCertNo($certNo, $certType);
            if ($certValidation !== true) {
                return $certValidation;
            }
        }

        // 校验最小年龄
        $minAge = $buyer['min_age'] ?? 0;
        if ($minAge !== 0 && $minAge < 14) {
            return '买家最小年龄(buyer.min_age)必须14岁以上';
        }

        // 校验手机号码
        $mobile = $this->filterString($buyer['mobile'] ?? null);
        if ($mobile !== null) {
            if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
                return '买家手机号码(buyer.mobile)格式错误，仅支持中国大陆11位手机号';
            }
        }

        return true;
    }

    /**
     * 根据证件类型验证证件号码格式
     *
     * @param string $certNo   证件号码
     * @param string $certType 证件类型
     * @return string|true 验证通过返回true,失败返回错误消息
     */
    private function validateCertNo(string $certNo, string $certType): string|true
    {
        return match ($certType) {
            // 中国大陆居民身份证（18位）
            'IDENTITY_CARD' => $this->validateIdentityCard($certNo),
            // 护照:英文字母开头,5-17位字母数字
            'PASSPORT' => preg_match('/^[A-Za-z][A-Za-z0-9]{4,16}$/', $certNo) ? true : '护照号码格式错误，应以字母开头，5-17位字母数字',
            // 军官证:通常由汉字、数字组成，8-18字符
            'OFFICER_CARD' => preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9]{6,18}$/u', $certNo) ? true : '军官证号码格式错误，8-18位汉字或字母数字',
            // 士兵证:同军官证规则
            'SOLDIER_CARD' => preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9]{6,18}$/u', $certNo) ? true : '士兵证号码格式错误，8-18位汉字或字母数字',
            // 户口簿:一般为户籍号，可以是身份证号格式
            'HOKOU' => preg_match('/^[A-Za-z0-9]{6,20}$/', $certNo) ? true : '户口簿编号格式错误，6-20位字母数字',
            // 外国人永久居留身份证:15位数字
            'PERMANENT_RESIDENCE_FOREIGNER' => preg_match('/^[A-Za-z0-9]{15,18}$/', $certNo) ? true : '外国人永久居留身份证号格式错误，15-18位',
            // 未知类型
            default => '未知证件类型',
        };
    }

    /**
     * 验证中国大陆居民身份证号码（18位）
     *
     * @param string $idCard 身份证号码
     * @return string|true 验证通过返回true,失败返回错误消息
     */
    private function validateIdentityCard(string $idCard): string|true
    {
        // 身份证必须为18位
        if (strlen($idCard) !== 18) {
            return '身份证号码必须为18位';
        }

        // 前17位必须为数字
        if (!preg_match('/^\d{17}/', $idCard)) {
            return '身份证号码前17位必须为数字';
        }

        // 最后一位可以是数字或X
        $lastChar = strtoupper($idCard[17]);
        if (!is_numeric($lastChar) && $lastChar !== 'X') {
            return '身份证号码最后一位必须为数字或X';
        }

        // 校验码验证
        $weights    = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $checkCodes = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
        $sum        = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int)$idCard[$i] * $weights[$i];
        }
        $expectedCheckCode = $checkCodes[$sum % 11];
        if ($lastChar !== $expectedCheckCode) {
            return '身份证号码校验码错误';
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

    /**
     * 构建接口类型相关的额外参数
     *
     * @param array $bizContent 业务参数
     * @return array 额外参数数组
     */
    private function buildMethodExtraParams(array $bizContent): array
    {
        $method = $bizContent['method'] ?? 'web';
        $params = [];

        switch ($method) {
            case 'jsapi':
                // JSAPI支付需要 sub_openid 和 sub_appid
                $params['sub_openid'] = $bizContent['sub_openid'] ?? null;
                $params['sub_appid']  = $bizContent['sub_appid'] ?? null;
                break;
            case 'scan':
                // 付款码支付需要 auth_code
                $params['auth_code'] = $bizContent['auth_code'] ?? null;
                break;
        }

        return $params;
    }
}
