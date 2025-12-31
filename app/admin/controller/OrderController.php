<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Merchant;
use app\model\Order;
use app\model\OrderRefund;
use app\model\PaymentChannel;
use app\model\PaymentChannelAccount;
use Core\baseController\AdminBase;
use Core\Service\OrderService;
use Core\Service\RefundService;
use Core\Utils\PaymentGatewayUtil;
use Exception;
use SodiumException;
use support\Db;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use Throwable;

class OrderController extends AdminBase
{
    /**
     * 订单列表
     */
    public function index(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $params = $request->only(['fuzzy_trade_no', 'trade_no', 'out_trade_no', 'api_trade_no', 'bill_trade_no', 'mch_trade_no', 'merchant_number', 'payment_type', 'payment_channel_code', 'payment_channel_account_id', 'subject', 'total_amount', 'buyer_pay_amount', 'receipt_amount', 'create_time', 'payment_time', 'trade_state', 'settle_state', 'notify_state']);

        try {
            validate([
                'fuzzy_trade_no'             => ['max:256', 'alphaDash'],
                'trade_no'                   => ['max:24', 'alphaNum'],
                'out_trade_no'               => ['max:128', 'alphaDash'],
                'api_trade_no'               => ['max:256', 'alphaDash'],
                'bill_trade_no'              => ['max:256', 'alphaDash'],
                'mch_trade_no'               => ['max:256', 'alphaDash'],
                'merchant_number'            => ['alphaNum', 'startWith:M', 'length:16'],
                'payment_channel_code'       => ['max:16', 'regex' => '/^[A-Z0-9]+$/'],
                'payment_channel_account_id' => ['number'],
                'subject'                    => ['max:255'],
                'total_amount'               => ['float'],
                'buyer_pay_amount'           => ['float'],
                'receipt_amount'             => ['float'],
                'create_time'                => ['array'],
                'payment_time'               => ['array']
            ], [
                'fuzzy_trade_no.max'                => '搜索单号不能超过256个字符',
                'fuzzy_trade_no.alphaDash'          => '搜索单号只能包含字母、数字、下划线和破折号',
                'trade_no.max'                      => '平台订单号不能超过24个字符',
                'trade_no.alphaNum'                 => '平台订单号只能包含字母和数字',
                'out_trade_no.max'                  => '商户订单号不能超过128个字符',
                'out_trade_no.alphaDash'            => '商户订单号只能包含字母、数字、下划线和破折号',
                'api_trade_no.max'                  => '上游订单号不能超过256个字符',
                'api_trade_no.alphaDash'            => '上游订单号只能包含字母、数字、下划线和破折号',
                'bill_trade_no.max'                 => '交易流水号不能超过256个字符',
                'bill_trade_no.alphaDash'           => '交易流水号只能包含字母、数字、下划线和破折号',
                'mch_trade_no.max'                  => '渠道流水号不能超过256个字符',
                'mch_trade_no.alphaDash'            => '渠道流水号只能包含字母、数字、下划线和破折号',
                'merchant_number.alphaNum'          => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.startWith'         => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.length'            => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'payment_channel_code.max'          => '通道编码不能超过16个字符',
                'payment_channel_code.regex'        => '通道编码只能包含大写字母和数字',
                'payment_channel_account_id.number' => '通道账户ID必须为数字',
                'subject:max'                       => '商品名称不能超过255个字',
                'total_amount.float'                => '订单金额必须为数字',
                'buyer_pay_amount.float'            => '付款金额必须为数字',
                'receipt_amount.float'              => '实收金额必须为数字',
                'create_time.array'                 => '创建时间范围格式不正确',
                'payment_time.array'                => '支付时间范围格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 构建查询
        $select_fields = ['trade_no', 'out_trade_no', 'merchant_id', 'payment_type', 'payment_channel_account_id', 'subject', 'total_amount', 'buyer_pay_amount', 'create_time', 'payment_time', 'trade_state', 'settle_state', 'notify_state'];
        $query         = Order::with(['merchant:id,merchant_number', 'paymentChannelAccount:id,name,payment_channel_id', 'paymentChannelAccount.paymentChannel:id,code', 'buyer:trade_no,ip'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'fuzzy_trade_no':
                        $q->where(function ($query) use ($value) {
                            $value = trim($value);
                            $query->orWhere('trade_no', $value)
                                ->orWhere('out_trade_no', $value)
                                ->orWhere('api_trade_no', $value)
                                ->orWhere('bill_trade_no', $value)
                                ->orWhere('mch_trade_no', $value);
                        });
                        break;
                    case 'trade_no':
                        $q->where('trade_no', 'like', "%$value%");
                        break;
                    case 'out_trade_no':
                        $q->where('out_trade_no', 'like', "%$value%");
                        break;
                    case 'api_trade_no':
                        $q->where('api_trade_no', 'like', "%$value%");
                        break;
                    case 'bill_trade_no':
                        $q->where('bill_trade_no', 'like', "%$value%");
                        break;
                    case 'mch_trade_no':
                        $q->where('mch_trade_no', 'like', "%$value%");
                        break;
                    case 'merchant_number':
                        $q->where('merchant_id', Merchant::where('merchant_number', $value)->value('id'));
                        break;
                    case 'payment_type':
                        $q->where('payment_type', $value);
                        break;
                    case 'payment_channel_code':
                        $q->whereIn('payment_channel_account_id', PaymentChannelAccount::where('payment_channel_id', PaymentChannel::where('code', $value)->value('id'))->pluck('id'));
                        break;
                    case 'payment_channel_account_id':
                        $q->where('payment_channel_account_id', $value);
                        break;
                    case 'subject':
                        $q->where('subject', 'like', "%$value%");
                        break;
                    case 'total_amount':
                        $q->where('total_amount', (float)$value);
                        break;
                    case 'receipt_amount':
                        $q->where('receipt_amount', (float)$value);
                        break;
                    case 'buyer_pay_amount':
                        $q->where('buyer_pay_amount', (float)$value);
                        break;
                    case 'create_time':
                        $q->whereBetween('create_time', [$value[0], $value[1]]);
                        break;
                    case 'payment_time':
                        $q->whereBetween('payment_time', [$value[0], $value[1]]);
                        break;
                    case 'trade_state':
                        $q->where('trade_state', $value);
                        break;
                    case 'settle_state':
                        $q->where('settle_state', $value);
                        break;
                    case 'notify_state':
                        $q->where('notify_state', $value);
                        break;
                }
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

    public function detail(Request $request): Response
    {
        $trade_no = $request->input('trade_no');

        if (empty($trade_no)) {
            return $this->fail('必要参数缺失');
        }

        $order = Order::with(['merchant:id,merchant_number', 'paymentChannelAccount:id,name,payment_channel_id', 'paymentChannelAccount.paymentChannel:id,code', 'buyer', 'refunds' => function ($query) {
            $query->orderBy('id', 'desc');
        }, 'notifications'                                                                                                                                                          => function ($query) {
            $query->orderBy('id', 'desc')->limit(20);
        }])->find($trade_no)->append(['payment_type_text', 'trade_state_text', 'settle_state_text', 'notify_state_text', 'payment_duration']);

        if (empty($order)) {
            return $this->fail('该订单不存在');
        }

        // 为已加载的 refunds 附加字段
        if ($order->relationLoaded('refunds')) {
            $order->refunds->each(fn($refund) => $refund->append(['initiate_type_text', 'status_text']));
        }

        return $this->success('获取成功', $order->toArray());
    }

    /**
     * 删除订单
     *
     * @param Request $request
     * @return Response
     */
    public function delete(Request $request): Response
    {
        $trade_no = $request->input('trade_no');

        if (empty($trade_no)) {
            return $this->fail('必要参数缺失');
        }

        if (!$order = Order::with('merchant:id,merchant_number')->find($trade_no, ['trade_no', 'merchant_id'])) {
            return $this->fail('该订单不存在');
        }

        try {
            DB::transaction(function () use ($order) {
                $order->buyer()->delete();
                $order->refunds()->delete();
                $order->notifications()->delete();
                $order->delete();
            });
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $merchant_number = $order->merchant->merchant_number ?? '未知';
        $this->adminLog("删除商户【{$merchant_number}】的订单【{$trade_no}】");

        return $this->success('删除成功');
    }

    /**
     * 重新通知下游
     *
     * @param Request $request
     * @return Response
     */
    public function reNotification(Request $request): Response
    {
        $trade_no = $request->input('trade_no');
        $type     = $request->input('type', 'server');

        if (empty($trade_no)) {
            return $this->fail('必要参数缺失');
        }
        if (!$order = Order::find($trade_no)) {
            return $this->fail('该订单不存在');
        }
        if (!in_array($order->trade_state, [Order::TRADE_STATE_SUCCESS, Order::TRADE_STATE_FINISHED], true)) {
            return $this->fail('该订单非交易成功或交易完结状态，无法重新通知');
        }

        try {
            if ($type === 'manual') {
                // manual模式：返回完整的cURL命令
                $fullData = OrderService::buildFullNotificationData($order);
                $jsonBody = json_encode($fullData['params']);

                $curlCommand = sprintf(
                    "curl -X POST '%s' -H 'Notification-Type: trade_state_sync' -H 'Notification-Id: manual' -H 'Notification-SignatureString: %s' -H 'Content-Type: application/json' -d '%s'",
                    $order->notify_url,
                    $fullData['sign_string'],
                    $jsonBody
                );

                return $this->success('异步通知cURL命令已生成', [
                    'sign_string'  => $fullData['sign_string'],
                    'curl_command' => $curlCommand,
                    'json_body'    => $jsonBody
                ]);
            } elseif ($type === 'sync') {
                // sync模式：同步通知
                $redirectUrl = OrderService::buildSyncNotificationParams($order->toArray());
                return $this->success('同步通知链接已生成', ['redirect_url' => $redirectUrl]);
            }

            // server模式：提交到队列
            OrderService::sendAsyncNotification($trade_no, $order, true);
            $merchant_number = $order->merchant->merchant_number ?? '未知';
            $this->adminLog("重新通知商户【{$merchant_number}】订单【{$trade_no}】");
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
        ])->where('trade_no', $trade_no)->selectRaw('`kkpay_order`.`trade_no`, `out_trade_no`, `payment_channel_account_id`, `buyer_pay_amount`,`trade_state`,(select sum(`kkpay_order_refund`.`amount`)  from `kkpay_order_refund`  where `kkpay_order`.`trade_no` = `kkpay_order_refund`.`trade_no`) as `refunds_sum_amount`')->first();
        if (empty($order)) {
            return $this->fail('订单不存在，请刷新页面后重试');
        }

        // 该订单已退款金额
        $refunded_amount = $order->refunds_sum_amount ?? '0';
        // 剩余可退款金额 = 实付金额 - 已退款金额
        $remaining_amount = bcsub($order->getOriginal('buyer_pay_amount'), $refunded_amount, 2);
        // 是否允许自动退款
        $allow_auto_refund = PaymentGatewayUtil::existMethod($order->paymentChannelAccount->paymentChannel->gateway, 'refund');
        $data              = array_merge($order->toArray(), [
            'allow_auto_refund' => $allow_auto_refund,
            'refunded_amount'   => $refunded_amount,
            'remaining_amount'  => $remaining_amount,
        ]);
        unset($data['payment_channel_account'], $data['payment_channel_account_id']);

        return $this->success('获取成功', $data);
    }

    /**
     * 退款
     *
     * @param Request $request
     * @return Response
     * @throws SodiumException
     */
    public function refund(Request $request): Response
    {
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        // 退款金额
        $amount = $params['amount'];
        // 退款类型
        $refund_type = $params['refund_type'] === 'auto';
        // 退款服务费承担方
        $fee_bearer = $params['fee_bearer'] === 'platform';
        // 退款原因
        $reason = $params['reason'] ?: null;

        if (empty($params['trade_no']) || empty($amount)) {
            return $this->fail('必要参数缺失');
        }

        if (!is_numeric($amount)) {
            return $this->fail('退款金额必须为数字');
        }

        $result = RefundService::handle($params['trade_no'], $amount, OrderRefund::INITIATE_TYPE_ADMIN, $refund_type, $fee_bearer, null, $reason);

        if ($result['state']) {
            $msg = "退款成功！本次退款金额: {$amount}元";
            if ($fee_bearer) {
                $msg .= "并退回商户平台服务费: {$result['refund_record']['refund_fee_amount']}元";
            }
            if ($refund_type) {
                $msg .= "，接口退款流水号: {$result['gateway_return']['api_refund_no']}";
            }
            $order           = Order::with('merchant:id,merchant_number')->find($params['trade_no'], ['trade_no', 'merchant_id']);
            $merchant_number = $order->merchant->merchant_number ?? '未知';
            $this->adminLog("为商户【{$merchant_number}】的订单【{$params['trade_no']}】执行退款操作，金额：{$amount}元");
            return $this->success($msg);
        }

        return $this->fail('退款失败: ' . $result['msg'] ?? '未知原因');
    }

    /**
     * 补单
     *
     * @param Request $request
     * @return Response
     */
    public function repair(Request $request): Response
    {
        $trade_no = $request->input('trade_no');

        if (empty($trade_no)) {
            return $this->fail('必要参数缺失');
        }
        if (!$order = Order::with('merchant')->where('trade_no', $trade_no)->first(['trade_no', 'trade_state', 'payment_channel_account_id', 'merchant_id'])) {
            return $this->fail('该订单不存在');
        }
        if ($order->payment_channel_account_id <= 0) {
            return $this->fail('该订单未匹配收款账户，禁止补单');
        }
        if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY && $order->trade_state !== Order::TRADE_STATE_CLOSED) {
            return $this->fail('该订单已被冻结或无需补单');
        }

        try {
            OrderService::handlePaymentSuccess(true, $order->trade_no, isAdmin: true);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        $merchant_number = $order->merchant->merchant_number ?? '未知';
        $this->adminLog("为商户【{$merchant_number}】的订单【{$order->trade_no}】执行补单操作");
        return $this->success('补单成功');
    }

    /**
     * 冻结或解冻
     *
     * @param Request $request
     * @return Response
     */
    public function freezeOrThaw(Request $request): Response
    {
        $trade_no    = $request->input('trade_no');
        $targetState = $request->input('target_state');

        if (empty($trade_no) || empty($targetState)) {
            return $this->fail('必要参数缺失');
        }
        if (!in_array($targetState, [Order::TRADE_STATE_FROZEN, Order::TRADE_STATE_SUCCESS], true)) {
            return $this->fail('参数异常');
        }
        if (!$order = Order::with('merchant')->where('trade_no', $trade_no)->first(['trade_no', 'trade_state', 'merchant_id'])) {
            return $this->fail('该订单不存在');
        }

        try {
            OrderService::handleFreezeOrThaw($order->trade_no, $targetState);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $operation       = $targetState === Order::TRADE_STATE_FROZEN ? '冻结' : '解冻';
        $merchant_number = $order->merchant->merchant_number ?? '未知';
        $this->adminLog("{$operation}商户【{$merchant_number}】的订单【{$order->trade_no}】");
        return $this->success("订单{$operation}成功");
    }
}
