<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Order;
use Core\baseController\AdminBase;
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
        $params = $request->only(['fuzzy_trade_no', 'trade_no', 'out_trade_no', 'api_trade_no', 'bill_trade_no', 'merchant_number', 'payment_type', 'payment_channel_account_id', 'subject', 'total_amount', 'receipt_amount', 'buyer_pay_amount', 'create_time', 'payment_time', 'trade_state', 'settle_state', 'notify_state']);

        try {
            validate([
                'account'    => 'max:32',
                'nickname'   => 'max:16',
                'email'      => 'max:64',
                'created_at' => 'array'
            ], [
                'account.max'      => '账号长度不能超过32位',
                'nickname.max'     => '昵称长度不能超过16位',
                'email.max'        => '邮箱长度不能超过64位',
                'created_at.array' => '请重新选择选择时间范围'
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
                    case 'account':
                        $q->where('account', $value);
                        break;
                    case 'email':
                        $q->where('email', 'like', "%$value%");
                        break;
                    case 'status':
                        $q->where('status', (int)$value);
                        break;
                    case 'created_at':
                        $q->whereBetween('created_at', [$value[0], $value[1]]);
                        break;
                }
            }
            return $q;
        });

        // 获取总数和数据
        $total = $query->count();
        $list  = $query->skip($from)->take($limit)->get()->append(['payment_type_text', 'trade_state_text', 'settle_state_text']);

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
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('退款成功');
    }
}
