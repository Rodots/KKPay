<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\PaymentChannel;
use app\model\PaymentType;
use core\baseController\AdminBase;
use support\Request;
use support\Response;

class PaymentChannelController extends AdminBase
{
    public function index(): string
    {
        return 'PaymentChannelController/index';
    }

    public function create(Request $request): Response
    {
        $param = $request->only(['name', 'title', 'mode', 'payment_type', 'gateway', 'rate', 'costs', 'min_amount', 'max_amount', 'day_receipt_limit', 'earliest_available_time', 'latest_available_time', 'roll_type']);

        if (empty($param['name']) || empty($param['title']) || empty($param['payment_type']) || empty($param['gateway'])) {
            return $this->fail('必要参数缺失');
        }

        if (PaymentChannel::where('name', $param['name'])->value('name')) {
            return $this->fail('该标识(' . $param['name'] . ')已被使用');
        }

        if (!PaymentType::find($param['payment_type'])) {
            return $this->fail('该支付类型不存在');
        }

        $paymentChannel = new PaymentChannel();
        $paymentChannel->name = strtoupper(trim($param['name']));
        $paymentChannel->title = trim($param['title']);
        $paymentChannel->mode = (int)($param['mode'] ?? 0);
        $paymentChannel->payment_type = $param['payment_type'];
        $paymentChannel->gateway = trim($param['gateway']);
        $paymentChannel->rate = (float)($param['rate'] ?? 100);
        $paymentChannel->costs = (float)($param['costs'] ?? 0);
        $paymentChannel->min_amount = (float)($param['min_amount'] ?? 0);
        $paymentChannel->max_amount = (float)($param['max_amount'] ?? 0);
        $paymentChannel->day_receipt_limit = (float)($param['day_receipt_limit'] ?? 0);
        $paymentChannel->earliest_available_time = $param['earliest_available_time'] ?? null;
        $paymentChannel->latest_available_time = $param['latest_available_time'] ?? null;
        $paymentChannel->roll_type = (int)($param['roll_type'] ?? 0);
        $paymentChannel->save();

        return $this->success('添加成功');
    }

    public function update(Request $request): Response
    {
        $param = $request->only(['id', 'name', 'title', 'mode', 'payment_type', 'gateway', 'rate', 'costs', 'min_amount', 'max_amount', 'day_receipt_limit', 'earliest_available_time', 'latest_available_time', 'roll_type']);

        if (empty($param['id']) || empty($param['name']) || empty($param['title']) || empty($param['payment_type']) || empty($param['gateway'])) {
            return $this->fail('必要参数缺失');
        }

        if (!$paymentChannel = PaymentChannel::find($param['id'])) {
            return $this->fail('该支付通道不存在');
        }

        if (PaymentChannel::where('name', $param['name'])->value('name') && $paymentChannel->name !== $param['name']) {
            return $this->fail('该标识(' . $param['name'] . ')已被使用');
        }

        $paymentChannel->name = strtoupper(trim($param['name']));
        $paymentChannel->title = trim($param['title']);
        $paymentChannel->mode = (int)($param['mode'] ?? 0);
        $paymentChannel->payment_type = $param['payment_type'];
        $paymentChannel->gateway = trim($param['gateway']);
        $paymentChannel->rate = (float)($param['rate'] ?? 100);
        $paymentChannel->costs = (float)($param['costs'] ?? 0);
        $paymentChannel->min_amount = (float)($param['min_amount'] ?? 0);
        $paymentChannel->max_amount = (float)($param['max_amount'] ?? 0);
        $paymentChannel->day_receipt_limit = (float)($param['day_receipt_limit'] ?? 0);
        $paymentChannel->earliest_available_time = $param['earliest_available_time'] ?? null;
        $paymentChannel->latest_available_time = $param['latest_available_time'] ?? null;
        $paymentChannel->roll_type = (int)($param['roll_type'] ?? 0);
        $paymentChannel->save();

        return $this->success('更新成功');
    }

    public function delete(Request $request): Response
    {
        $id = $request->post('id');

        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        if (!$paymentChannel = PaymentChannel::find($id)) {
            return $this->fail('该支付通道不存在');
        }

        $paymentChannel->delete();

        return $this->success('删除成功');
    }
}
