<?php

declare(strict_types=1);

namespace app\merchant\controller;

use app\model\Order;
use app\model\OrderRefund;
use Core\baseController\MerchantBase;
use Core\Service\OrderService;
use Core\Service\RefundService;
use Core\Utils\PaymentGatewayUtil;
use Exception;
use support\Request;
use support\Response;
use Throwable;

/**
 * 商户端 - 订单管理控制器
 */
class OrderController extends MerchantBase
{
    /**
     * 订单列表
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $merchantId = $request->MerchantInfo['id'];
        $from       = $request->get('from', 0);
        $limit      = $request->get('limit', 20);
        $params     = $request->only(['trade_no', 'out_trade_no', 'payment_type', 'subject', 'total_amount', 'create_time', 'payment_time', 'trade_state', 'notify_state', 'ip', 'user_id', 'buyer_open_id']);

        try {
            validate([
                'trade_no'     => ['max:24', 'alphaNum'],
                'out_trade_no' => ['max:128', 'alphaDash'],
                'subject'      => ['max:255'],
                'total_amount' => ['float'],
                'create_time'  => ['array'],
                'payment_time' => ['array'],
                'ip'           => ['max:45'],
                'user_id'      => ['max:255'],
                'buyer_open_id' => ['max:128'],
            ], [
                'trade_no.max'           => '平台订单号不能超过24个字符',
                'trade_no.alphaNum'      => '平台订单号只能包含字母和数字',
                'out_trade_no.max'       => '商户订单号不能超过128个字符',
                'out_trade_no.alphaDash' => '商户订单号只能包含字母、数字、下划线和破折号',
                'subject.max'            => '商品名称不能超过255个字',
                'total_amount.float'     => '订单金额必须为数字',
                'create_time.array'      => '创建时间范围格式不正确',
                'payment_time.array'     => '支付时间范围格式不正确',
                'ip.max'                 => '买家IP不能超过45个字符',
                'user_id.max'            => '买家用户ID不能超过255个字符',
                'buyer_open_id.max'      => '买家OpenID不能超过128个字符',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 构建查询
        $select_fields = ['trade_no', 'out_trade_no', 'payment_type', 'payment_channel_account_id', 'subject', 'total_amount', 'buyer_pay_amount', 'create_time', 'payment_time', 'trade_state', 'settle_state', 'notify_state'];
        $query         = Order::with(['paymentChannelAccount:id,payment_channel_id', 'paymentChannelAccount.paymentChannel:id,code'])->where('merchant_id', $merchantId)->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $value = is_string($value) ? trim($value) : $value;
                match ($key) {
                    'trade_no' => $q->where('trade_no', 'like', "%$value%"),
                    'out_trade_no' => $q->where('out_trade_no', 'like', "%$value%"),
                    'payment_type' => $q->where('payment_type', $value),
                    'subject' => $q->where('subject', 'like', "%$value%"),
                    'total_amount' => $q->where('total_amount', (float)$value),
                    'create_time' => $q->whereBetween('create_time', [$value[0], $value[1]]),
                    'payment_time' => $q->whereBetween('payment_time', [$value[0], $value[1]]),
                    'trade_state' => $q->where('trade_state', $value),
                    'notify_state' => $q->where('notify_state', $value),
                    'ip' => $q->whereHas('buyer', fn($query) => $query->where('ip', $value)),
                    'user_id' => $q->whereHas('buyer', fn($query) => $query->where('user_id', $value)),
                    'buyer_open_id' => $q->whereHas('buyer', fn($query) => $query->where('buyer_open_id', $value)),
                    default => null,
                };
            }
            return $q;
        });

        // 获取总数和数据
        $total = $query->count();
        $list  = $query->offset($from)->limit($limit)->orderBy('create_time', 'desc')->get($select_fields)->append(['payment_type_text', 'trade_state_text', 'settle_state_text', 'notify_state_text', 'payment_duration']);

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 订单详情
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function detail(Request $request): Response
    {
        $trade_no   = $request->input('trade_no');
        $merchantId = $request->MerchantInfo['id'];

        if (empty($trade_no)) {
            return $this->fail('必要参数缺失');
        }

        $order = Order::with([
            'buyer:trade_no,ip,user_id,buyer_open_id',
            'refunds'       => function ($query) {
                $query->orderBy('id', 'desc');
            },
            'notifications' => function ($query) {
                $query->orderBy('id', 'desc')->limit(20);
            },
        ])->where('merchant_id', $merchantId)->find($trade_no);

        if (empty($order)) {
            return $this->fail('该订单不存在');
        }

        $order->append(['payment_type_text', 'trade_state_text', 'notify_state_text', 'payment_duration', 'user_behavior_summary']);

        // 为已加载的 refunds 附加字段
        if ($order->relationLoaded('refunds')) {
            $order->refunds->each(fn($refund) => $refund->append(['initiate_type_text', 'status_text']));
        }

        // 精简数据：去除内部字段
        $data = $order->toArray();
        unset($data['merchant_id'], $data['payment_channel_account_id'], $data['api_trade_no'], $data['mch_trade_no'], $data['fee_amount'], $data['profit_amount'], $data['settle_state'], $data['settle_cycle']);

        return $this->success('获取成功', $data);
    }

    /**
     * 重新通知下游
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function reNotification(Request $request): Response
    {
        $trade_no = $request->input('trade_no');

        if (empty($trade_no)) {
            return $this->fail('必要参数缺失');
        }
        if (!$order = Order::where('trade_no', $trade_no)->where('merchant_id', $request->MerchantInfo['id'])->first(['trade_no', 'out_trade_no', 'merchant_id', 'bill_trade_no', 'total_amount', 'buyer_pay_amount', 'receipt_amount', 'attach', 'trade_state', 'create_time', 'payment_time', 'sign_type', 'notify_url', 'return_url'])) {
            return $this->fail('该订单不存在');
        }
        if (!in_array($order->trade_state, [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_REFUND, Order::TRADE_STATE_FINISHED], true)) {
            return $this->fail('该订单当前交易状态不允许重新通知');
        }

        try {
            // 商户端仅支持server模式（异步通知）
            OrderService::sendAsyncNotification($trade_no, $order, true);
            $this->merchantLog("重新通知订单【{$trade_no}】");
            return $this->success('重新通知任务已加入队列，系统将异步处理');
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 退款前置
     *
     * @param Request $request
     * @return Response
     */
    public function refundInfo(Request $request): Response
    {
        $trade_no = $request->input('trade_no');

        if (empty($trade_no)) {
            return $this->fail('必要参数缺失');
        }

        $order = Order::with([
            'paymentChannelAccount' => function ($query) {
                $query->select(['id', 'payment_channel_id'])->with('paymentChannel:id,gateway');
            }
        ])->selectRaw('`kkpay_order`.`trade_no`, `out_trade_no`, `payment_channel_account_id`, `buyer_pay_amount`, `trade_state`, (select sum(`kkpay_order_refund`.`amount`) from `kkpay_order_refund` where `kkpay_order`.`trade_no` = `kkpay_order_refund`.`trade_no`) as `refunds_sum_amount`')->where('merchant_id', $request->MerchantInfo['id'])->where('trade_no', $trade_no)->first();
        if (empty($order)) {
            return $this->fail('订单不存在，请刷新页面后重试');
        }

        // 该订单已退款金额
        $refunded_amount = $order->refunds_sum_amount ?? '0';
        // 剩余可退款金额 = 实付金额 - 已退款金额
        $remaining_amount = bcsub($order->buyer_pay_amount, $refunded_amount, 2);
        // 是否允许自动退款
        $allow_auto_refund = PaymentGatewayUtil::methodExists($order->paymentChannelAccount->paymentChannel->gateway, 'refund');
        $data              = array_merge($order->attributesToArray(), [
            'allow_auto_refund' => $allow_auto_refund,
            'refunded_amount'   => $refunded_amount,
            'remaining_amount'  => $remaining_amount,
        ]);
        unset($data['payment_channel_account_id']);

        return $this->success('获取成功', $data);
    }

    /**
     * 退款
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function refund(Request $request): Response
    {
        $trade_no = $request->post('trade_no');
        $amount   = $request->post('amount');
        $reason   = $request->post('reason') ?: null;

        if (empty($trade_no) || empty($amount)) {
            return $this->fail('必要参数缺失');
        }

        if (!is_numeric($amount)) {
            return $this->fail('退款金额必须为数字');
        }

        // 验证订单归属当前商户
        if (!Order::where('trade_no', $trade_no)->where('merchant_id', $request->MerchantInfo['id'])->exists()) {
            return $this->fail('该订单不存在');
        }

        // 商户发起退款：自动退款、退款服务费由平台承担（false = 商户承担）
        $result = RefundService::handle($trade_no, $amount, OrderRefund::INITIATE_TYPE_MERCHANT, true, false, null, $reason);

        if ($result['state']) {
            $msg = "退款成功！本次退款金额: {$amount}元";
            $this->merchantLog("为订单【{$trade_no}】执行退款操作，金额：{$amount}元");
            return $this->success($msg);
        }

        return $this->fail('退款失败: ' . $result['msg'] ?? '未知原因');
    }

    /**
     * 关闭订单
     *
     * @param Request $request HTTP请求对象
     * @return Response 响应对象
     */
    public function close(Request $request): Response
    {
        $trade_no = $request->input('trade_no');

        if (empty($trade_no)) {
            return $this->fail('必要参数缺失');
        }

        // 验证订单归属当前商户
        if (!Order::where('trade_no', $trade_no)->where('merchant_id', $request->MerchantInfo['id'])->exists()) {
            return $this->fail('该订单不存在');
        }

        $result = OrderService::handleOrderClose($trade_no, true);

        if (!$result['state']) {
            return $this->fail($result['message']);
        }

        $this->merchantLog("关闭订单【{$trade_no}】");

        return $this->success($result['message']);
    }

    /**
     * 批量重新通知下游
     *
     * @param Request $request
     * @return Response
     */
    public function batchReNotification(Request $request): Response
    {
        $ids = $request->post('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->fail('必要参数缺失');
        }

        $success = [];
        $failed  = [];

        foreach ($ids as $trade_no) {
            try {
                $order = Order::where('trade_no', $trade_no)->where('merchant_id', $request->MerchantInfo['id'])->first(['trade_no', 'out_trade_no', 'merchant_id', 'bill_trade_no', 'total_amount', 'buyer_pay_amount', 'receipt_amount', 'attach', 'trade_state', 'create_time', 'payment_time', 'sign_type', 'notify_url', 'return_url']);

                if (!$order) {
                    $failed[$trade_no] = '订单不存在';
                    continue;
                }
                if (!in_array($order->trade_state, [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_REFUND, Order::TRADE_STATE_FINISHED], true)) {
                    $failed[$trade_no] = '订单非交易成功、部分退款或全额退款状态';
                    continue;
                }

                OrderService::sendAsyncNotification($trade_no, $order, true);
                $success[] = $trade_no;

                $this->merchantLog("批量重新通知：订单【{$trade_no}】");
            } catch (Throwable $e) {
                $failed[$trade_no] = $e->getMessage();
            }
        }

        return $this->success('批量重新通知任务已加入队列', [
            'success_count' => count($success),
            'failed_count'  => count($failed),
            'success'       => $success,
            'failed'        => $failed,
        ]);
    }

    /**
     * 批量关闭订单
     *
     * @param Request $request
     * @return Response
     */
    public function batchClose(Request $request): Response
    {
        $ids = $request->post('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->fail('必要参数缺失');
        }

        $success = [];
        $failed  = [];

        foreach ($ids as $trade_no) {
            try {
                $order = Order::where('trade_no', $trade_no)->where('merchant_id', $request->MerchantInfo['id'])->first(['trade_no', 'trade_state', 'merchant_id']);

                if (!$order) {
                    $failed[$trade_no] = '订单不存在';
                    continue;
                }

                $result = OrderService::handleOrderClose($trade_no, true);

                if ($result['state'] && !$result['gateway_return']['state']) {
                    $failed[$trade_no] = $result['gateway_return']['message'];
                    continue;
                }
                if (!$result['state']) {
                    $failed[$trade_no] = $result['message'];
                    continue;
                }

                $success[] = $trade_no;

                $this->merchantLog("批量关闭：订单【{$trade_no}】");
            } catch (Throwable $e) {
                $failed[$trade_no] = $e->getMessage();
            }
        }

        return $this->success('批量关闭完成', [
            'success_count' => count($success),
            'failed_count'  => count($failed),
            'success'       => $success,
            'failed'        => $failed,
        ]);
    }
}
