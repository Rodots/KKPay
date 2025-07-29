<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\PaymentChannel;
use app\model\PaymentChannelAccount;
use core\baseController\AdminBase;
use support\Request;
use support\Response;

class PaymentChannelAccountController extends AdminBase
{
    public function index(): string
    {
        return 'PaymentChannelAccountController/index';
    }

    public function create(Request $request): Response
    {
        $param = $request->only(['name', 'payment_channel_id', 'rate_type', 'rate', 'config']);

        if (empty($param['name']) || empty($param['payment_channel_id']) || empty($param['config'])) {
            return $this->fail('必要参数缺失');
        }

        if (!PaymentChannel::find($param['payment_channel_id'])) {
            return $this->fail('获取关联支付通道失败');
        }

        $paymentChannelAccount = new PaymentChannelAccount();
        $paymentChannelAccount->name = trim($param['name']);
        $paymentChannelAccount->payment_channel_id = $param['payment_channel_id'];
        $paymentChannelAccount->rate_type = (int)($param['rate_type'] ?? 0);
        $paymentChannelAccount->rate = (float)($param['rate'] ?? 0);
        $paymentChannelAccount->config = $param['config'];
        $paymentChannelAccount->save();

        return $this->success('添加成功');
    }

    public function update(Request $request): Response
    {
        $param = $request->only(['id', 'name', 'payment_channel_id', 'rate_type', 'rate', 'config']);

        if (empty($param['id']) || empty($param['name']) || empty($param['payment_channel_id']) || empty($param['config'])) {
            return $this->fail('必要参数缺失');
        }

        if (!$paymentChannelAccount = PaymentChannelAccount::find($param['id'])) {
            return $this->fail('该支付通道子账户不存在');
        }

        $paymentChannelAccount->name = trim($param['name']);
        $paymentChannelAccount->payment_channel_id = $param['payment_channel_id'];
        $paymentChannelAccount->rate_type = (int)($param['rate_type'] ?? 0);
        $paymentChannelAccount->rate = (float)($param['rate'] ?? 0);
        $paymentChannelAccount->config = $param['config'];
        $paymentChannelAccount->save();

        return $this->success('更新成功');
    }

    public function delete(Request $request): Response
    {
        $id = $request->post('id');

        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        if (!$paymentChannelAccount = PaymentChannelAccount::find($id)) {
            return $this->fail('该支付通道子账户不存在');
        }

        $paymentChannelAccount->delete();

        return $this->success('删除成功');
    }
}
