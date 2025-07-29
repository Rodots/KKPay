<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Order;
use core\baseController\AdminBase;
use support\Db;
use support\Request;
use support\Response;

class OrderController extends AdminBase
{
    public function index(): string
    {
        return 'OrderController/index';
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
