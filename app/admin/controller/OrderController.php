<?php

declare(strict_types = 1);

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
                'fuzzy_trade_no.max'                => '五合一单号长度不能超过256位',
                'fuzzy_trade_no.alphaDash'          => '五合一单号只能是英文字母和数字，下划线及破折号',
                'trade_no.max'                      => '平台订单号长度不能超过24位',
                'trade_no.alphaNum'                 => '平台订单号只能是英文字母和数字',
                'out_trade_no.max'                  => '商户订单号长度不能超过128位',
                'out_trade_no.alphaDash'            => '商户订单号只能是英文字母和数字，下划线及破折号',
                'api_trade_no.max'                  => '上游订单号长度不能超过256位',
                'api_trade_no.alphaDash'            => '上游订单号只能是英文字母和数字，下划线及破折号',
                'bill_trade_no.max'                 => '交易流水号长度不能超过256位',
                'bill_trade_no.alphaDash'           => '交易流水号只能是英文字母和数字，下划线及破折号',
                'mch_trade_no.max'                  => '渠道交易流水号长度不能超过256位',
                'mch_trade_no.alphaDash'            => '渠道交易流水号只能是英文字母和数字，下划线及破折号',
                'merchant_number.alphaNum'          => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.startWith'         => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.length'            => '商户编号是以M开头的16位数字+英文组合',
                'payment_channel_code.max'          => '支付通道编码长度不能超过16位',
                'payment_channel_code.regex'        => '支付通道编码只能是大写英文字母和数字',
                'payment_channel_account_id.number' => '支付通道账户ID只能是数字',
                'subject:max'                       => '商品名称长度不能超过255位',
                'total_amount.float'                => '订单金额格式不正确',
                'buyer_pay_amount.float'            => '用户付款金额格式不正确',
                'receipt_amount.float'              => '商户实收金额格式不正确',
                'create_time.array'                 => '请重新选择选择时间范围',
                'payment_time.array'                => '请重新选择选择时间范围'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 构建查询
        $select_fields = ['trade_no', 'out_trade_no', 'merchant_id', 'payment_type', 'payment_channel_account_id', 'subject', 'total_amount', 'create_time', 'payment_time', 'trade_state', 'settle_state', 'notify_state'];
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

        if (!$order = Order::find($trade_no)) {
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
        if ($order->trade_state !== Order::TRADE_STATE_SUCCESS) {
            return $this->fail('该订单非交易成功状态，无法重新通知');
        }

        try {
            if ($type === 'manual') {
                $sign_string = OrderService::sendAsyncNotification($trade_no, $order, isServer: false);
                $curlCommand = "curl -X POST '" . $order->notify_url . "' -H 'Notification-Type: trade_state_sync' -H 'Notification-Id: manual' -H 'Content-Type: application/json' -d '" . $sign_string . "'";
                return $this->success('异步通知cURL命令已生成', ['curl_command' => $curlCommand, 'sign_string' => $sign_string]);
            } elseif ($type === 'sync') {
                $redirect_url = OrderService::buildSyncNotificationParams($order->toArray());
                return $this->success('同步通知链接已生成', ['redirect_url' => $redirect_url]);
            }

            $sign_string = OrderService::sendAsyncNotification($trade_no, $order, 'order-notification-manual');
            return $this->success('重新通知任务已提交，系统将异步处理', ['sign_string' => $sign_string]);
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
        // 订单号
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
        // 订单号
        $trade_no = $request->input('trade_no');

        if (empty($trade_no)) {
            return $this->fail('必要参数缺失');
        }
        if (!$order = Order::where('trade_no', $trade_no)->first(['trade_no', 'trade_state'])) {
            return $this->fail('该订单不存在');
        }
        if ($order->trade_state !== Order::TRADE_STATE_WAIT_PAY && $order->trade_state !== Order::TRADE_STATE_CLOSED) {
            return $this->fail('该订单已被冻结或无需补单');
        }

        try {
            OrderService::handlePaymentSuccess(true, $order->trade_no, isAdmin: true);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('补单成功');
    }
}
