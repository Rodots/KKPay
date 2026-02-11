<?php

declare(strict_types=1);

namespace app\api\controller;

use app\model\Merchant;
use app\model\Order;
use app\model\OrderBuyer;
use app\model\PaymentChannel;
use Core\Exception\PaymentException;
use Core\Service\OrderCreationService;
use Core\Service\PaymentChannelSelectionService;
use Core\Service\PaymentService;
use Core\Traits\ApiResponse;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 收银台控制器
 *
 * 处理收银台页面展示和支付方式选择
 * 当页面跳转支付流程中指定通道不可用时，用户被引导至此收银台选择其他支付方式
 */
class CheckoutController
{
    use ApiResponse;

    /**
     * 支付方式扩展信息（描述、主题色、标签）
     */
    private const array PAYMENT_TYPE_META = [
        Order::PAYMENT_TYPE_ALIPAY    => ['desc' => '10亿用户都在用，真安全，更方便', 'themeColor' => '#1677FF', 'tags' => ['推荐']],
        Order::PAYMENT_TYPE_WECHATPAY => ['desc' => '亿万用户的选择', 'themeColor' => '#22AC38', 'tags' => ['推荐']],
        Order::PAYMENT_TYPE_BANK      => ['desc' => '支持各大银行借记卡、信用卡', 'themeColor' => '#C01820', 'tags' => []],
        Order::PAYMENT_TYPE_UNIONPAY  => ['desc' => '银行业统一移动支付', 'themeColor' => '#E7191F', 'tags' => []],
        Order::PAYMENT_TYPE_QQWALLET  => ['desc' => 'QQ支付，轻松便捷', 'themeColor' => '#0BB2FF', 'tags' => []],
        Order::PAYMENT_TYPE_JDPAY     => ['desc' => '京东旗下支付平台', 'themeColor' => '#E4393C', 'tags' => []],
        Order::PAYMENT_TYPE_PAYPAL    => ['desc' => '全球领先的在线支付平台', 'themeColor' => '#003087', 'tags' => []],
    ];

    /**
     * 收银台页面
     *
     * 展示订单信息和可用的支付方式，供用户选择
     * 路由：GET /checkout/{orderNo}.html
     *
     * @param Request $request 请求对象
     * @param string  $orderNo 平台订单号
     * @return Response HTML页面响应
     */
    public function index(Request $request, string $orderNo): Response
    {
        try {
            // 校验订单
            $order = $this->loadAndValidateOrder($orderNo);
            if ($order instanceof Response) {
                return $order;
            }

            // 加载商户信息
            $merchant = Merchant::where('id', $order->merchant_id)->first(['id', 'buyer_pay_fee', 'channel_whitelist']);
            if (!$merchant) {
                return $this->pageMsg('商户信息异常');
            }

            // 获取商户可用的支付方式
            $paymentTypes = $this->getAvailablePaymentTypes($merchant);
            if (empty($paymentTypes)) {
                return $this->pageMsg('暂无可用的支付方式，请联系平台客服');
            }

            // 渲染收银台页面
            return raw_view('/app/api/view/checkout', [
                'order'           => $order->toArray(),
                'paymentTypes'    => $paymentTypes,
                'formattedAmount' => '¥' . number_format((float)$order['total_amount'], 2),
                'isFirst'         => true
            ]);
        } catch (Throwable $e) {
            Log::error('收银台页面加载异常', [
                'order_no' => $orderNo,
                'error'    => $e->getMessage(),
            ]);
            return $this->pageMsg('页面加载失败，请稍后重试');
        }
    }

    /**
     * 选择支付方式并发起支付
     *
     * 接收用户选择的支付类型，选择通道、更新订单、发起支付并返回支付结果
     * 路由：POST /checkout/{orderNo}/pay
     *
     * @param Request $request 请求对象
     * @param string  $orderNo 平台订单号
     * @return Response JSON响应
     */
    public function pay(Request $request, string $orderNo): Response
    {
        try {
            // 校验 Referer 来源，确保请求来自收银台页面
            $referer      = $request->header('referer', '');
            $expectedPath = "/checkout/$orderNo.html";
            if (empty($referer) || !str_contains(parse_url($referer, PHP_URL_PATH) ?? '', $expectedPath)) {
                return $this->fail('非法请求');
            }

            // 校验订单
            $order = $this->loadAndValidateOrder($orderNo);
            if ($order instanceof Response) {
                return $this->fail('订单状态异常，无法支付');
            }

            // 获取并验证支付类型
            $paymentType = trim($request->post('payment_type', ''));
            if (empty($paymentType) || !Order::checkPaymentType($paymentType)) {
                return $this->fail('请选择有效的支付方式');
            }

            // 加载商户信息
            $merchant = Merchant::where('id', $order->merchant_id)->first(['id', 'merchant_number', 'email', 'mobile', 'diy_order_subject', 'buyer_pay_fee', 'channel_whitelist']);
            if (!$merchant) {
                return $this->fail('商户信息异常');
            }

            // 选择支付通道
            $paymentChannelAccount = PaymentChannelSelectionService::selectByType($paymentType, $order->total_amount, $merchant);

            // 更新订单的支付通道信息
            OrderCreationService::updateOrderChannel($order, $paymentChannelAccount, $merchant);

            // 加载买家信息
            $orderBuyer = OrderBuyer::where('trade_no', $order->trade_no)->first();
            if (!$orderBuyer) {
                return $this->fail('订单信息不完整');
            }

            // 重新加载订单数据（已更新通道信息）
            $orderData = $order->fresh()->toArray();

            // 将商户信息挂载到请求上下文（PaymentService::initiatePayment 内部需要）
            $request->merchant = $merchant;

            // 发起支付
            $paymentResult = PaymentService::initiatePayment($orderData, $paymentChannelAccount, $orderBuyer);

            // 格式化为 JSON 响应
            $echoJson = PaymentService::echoJson($paymentResult, $orderData);

            return $this->success($echoJson);
        } catch (PaymentException $e) {
            Log::warning('[收银台支付]失败:' . $e->getMessage());
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::error('[收银台支付]异常', [
                'order_no' => $orderNo,
                'error'    => $e->getMessage(),
            ]);
            return $this->fail('支付发起失败，请稍后重试');
        }
    }

    /**
     * 加载并校验订单
     *
     * @param string $orderNo 平台订单号
     * @return Order|Response 有效订单对象，或错误页面响应
     */
    private function loadAndValidateOrder(string $orderNo): Order|Response
    {
        if (empty($orderNo)) {
            return $this->pageMsg('订单号不能为空');
        }

        // 校验订单号格式
        if (!preg_match('/^P\d{18}[A-Z]{5}$/', $orderNo)) {
            return $this->pageMsg('订单号格式错误');
        }

        $order = Order::where('trade_no', $orderNo)->first();
        if (!$order) {
            return $this->pageMsg('订单不存在');
        }

        // 检查订单状态
        if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY) {
            return $this->pageMsg('订单状态异常，无法支付');
        }

        // 检查订单是否已过期
        if ($this->getRemainingSeconds($order) <= 0) {
            return $this->pageMsg('订单已过期，请重新下单');
        }

        return $order;
    }

    /**
     * 计算订单剩余有效时间（秒）
     *
     * 优先使用订单的 expire_time 字段，若未设置则默认创建后 30 分钟过期
     *
     * @param Order $order 订单对象
     * @return int 剩余秒数，已过期返回 0
     */
    private function getRemainingSeconds(Order $order): int
    {
        $expireTime = $order->getAttributeValue('expire_time');

        if (!empty($expireTime)) {
            $expireTimestamp = strtotime($expireTime);
        } else {
            // 默认创建后 30 分钟过期
            $expireTimestamp = strtotime($order->getAttributeValue('create_time')) + 1800;
        }

        return max(0, $expireTimestamp - time());
    }

    /**
     * 获取商户可用的支付方式列表
     *
     * 查询商户白名单内已启用的支付通道，按 payment_type 分组返回
     *
     * @param Merchant $merchant 商户对象
     * @return array 可用支付方式列表 [{type, name}, ...]
     */
    private function getAvailablePaymentTypes(Merchant $merchant): array
    {
        $whitelistEnabled = sys_config('payment', 'enable_merchant_channel_whitelist', 'on') === 'on';

        $query = PaymentChannel::where('status', true);

        if ($whitelistEnabled) {
            if (!$merchant->hasChannelWhitelist()) {
                return [];
            }
            $query->whereIn('id', $merchant->getAvailableChannelIds());
        }

        // 按 payment_type 去重查询
        $types = $query->distinct()->pluck('payment_type')->toArray();

        // 构建支付方式列表
        $result = [];
        foreach ($types as $type) {
            $name = Order::PAYMENT_TYPE_MAP[$type] ?? null;
            if ($name !== null) {
                $meta     = self::PAYMENT_TYPE_META[$type] ?? [];
                $result[] = [
                    'type'       => $type,
                    'name'       => $name,
                    'desc'       => $meta['desc'] ?? '',
                    'themeColor' => $meta['themeColor'] ?? '#1677FF',
                    'tags'       => $meta['tags'] ?? [],
                ];
            }
        }

        return $result;
    }
}
