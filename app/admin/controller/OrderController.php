<?php

declare(strict_types = 1);

namespace app\admin\controller;

use app\model\Order;
use app\model\PaymentChannel;
use app\model\PaymentChannelAccount;
use Core\baseController\AdminBase;
use Exception;
use support\Db;
use support\Request;
use support\Response;
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
        $params = $request->only(['fuzzy_trade_no', 'trade_no', 'out_trade_no', 'api_trade_no', 'bill_trade_no', 'merchant_number', 'payment_type', 'payment_channel_code', 'payment_channel_account_id', 'subject', 'total_amount', 'buyer_pay_amount', 'receipt_amount', 'create_time', 'payment_time', 'trade_state', 'settle_state', 'notify_state']);

        try {
            validate([
                'fuzzy_trade_no'             => ['max:256', 'alphaDash'],
                'trade_no'                   => ['max:28', 'alphaDash'],
                'out_trade_no'               => ['max:128', 'alphaDash'],
                'api_trade_no'               => ['max:256', 'alphaDash'],
                'bill_trade_no'              => ['max:256', 'alphaDash'],
                'merchant_number'            => ['alphaNum', 'startWith:M', 'length:24'],
                'payment_channel_code'       => ['max:16', 'alphaNum', 'upper'],
                'payment_channel_account_id' => ['number'],
                'subject'                    => ['max:255'],
                'total_amount'               => ['float'],
                'buyer_pay_amount'           => ['float'],
                'receipt_amount'             => ['float'],
                'create_time'                => ['array'],
                'payment_time'               => ['array']
            ], [
                'fuzzy_trade_no.max'                => '四合一单号长度不能超过256位',
                'fuzzy_trade_no.alphaDash'          => '四合一单号只能是字母和数字，下划线及破折号',
                'trade_no.max'                      => '平台订单号长度不能超过256位',
                'trade_no.alphaDash'                => '平台订单号只能是字母和数字，下划线及破折号',
                'out_trade_no.max'                  => '商户订单号长度不能超过256位',
                'out_trade_no.alphaDash'            => '商户订单号只能是字母和数字，下划线及破折号',
                'api_trade_no.max'                  => '上游订单号长度不能超过256位',
                'api_trade_no.alphaDash'            => '上游订单号只能是字母和数字，下划线及破折号',
                'bill_trade_no.max'                 => '交易流水号长度不能超过256位',
                'bill_trade_no.alphaDash'           => '交易流水号只能是字母和数字，下划线及破折号',
                'merchant_number.alphaNum'          => '商户编号是以M开头的24位英文+数字',
                'merchant_number.startWith'         => '商户编号是以M开头的24位英文+数字',
                'merchant_number.length'            => '商户编号是以M开头的24位英文+数字',
                'payment_channel_code.max'          => '支付通道编码长度不能超过16位',
                'payment_channel_code.alphaNum'     => '支付通道编码只能是大写字母和数字',
                'payment_channel_code.upper'        => '支付通道编码只能是大写字母和数字',
                'payment_channel_account_id.number' => '支付通道账户ID只能是数字',
                'subject:max'                       => '商品名称长度不能超过255位',
                'total_amount.float'                => '订单总金额格式不正确',
                'buyer_pay_amount.float'            => '用户付款金额格式不正确',
                'receipt_amount.float'              => '商户实收金额格式不正确',
                'create_time.array'                 => '请重新选择选择时间范围',
                'payment_time.array'                => '请重新选择选择时间范围'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 构建查询
        $query = Order::with(['merchant:id,merchant_number', 'paymentChannelAccount:id,name,payment_channel_id', 'paymentChannelAccount.paymentChannel:id,gateway'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'fuzzy_trade_no':
                        $q->orWhere(function ($query) use ($value) {
                            $value = trim($value);
                            $query->where('trade_no', $value)
                                ->where('out_trade_no', $value)
                                ->where('api_trade_no', $value)
                                ->where('bill_trade_no', $value);
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
                    case 'merchant_number':
                        $q->where('merchant_number', trim($value));
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
                        $q->where('notify_state', (bool)$value);
                        break;
                }
            }
            return $q;
        });

        // 获取总数和数据
        $total = $query->count();
        $list  = $query->skip($from)->take($limit)->orderBy('create_time', 'desc')->get()->append(['payment_type_text', 'trade_state_text', 'settle_state_text']);

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

        $order = Order::find($trade_no);

        if (empty($order)) {
            return $this->fail('该订单不存在');
        }

        return $this->success('获取成功', $order->toArray());
    }

    public function refund(Request $request): Response
    {
        // 订单号
        $trade_no = $request->input('trade_no');
        // 退款金额
        $amount = $request->input('amount');

        if (empty($trade_no) || empty($amount)) {
            return $this->fail('必要参数缺失');
        }

        if (!is_numeric($amount)) {
            return $this->fail('退款金额必须为数字');
        }

        try {
            DB::transaction(function () use ($trade_no, $amount) {
                // 在这里执行你的数据库操作
                $order = Order::find($trade_no);
                $order->refundProcessing((float)$amount);
            });
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('退款成功');
    }
}
