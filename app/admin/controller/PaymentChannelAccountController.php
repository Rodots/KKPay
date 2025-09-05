<?php

declare(strict_types = 1);

namespace App\admin\controller;

use App\model\PaymentChannel;
use App\model\PaymentChannelAccount;
use Core\baseController\AdminBase;
use support\Request;
use support\Response;
use Throwable;

class PaymentChannelAccountController extends AdminBase
{
    /**
     * 支付通道子账户列表
     */
    public function index(Request $request): Response
    {
        $from       = $request->get('from', 0);
        $limit      = $request->get('limit', 10);
        $sort       = $request->get('sort', 'id');
        $order      = $request->get('order', 'desc');
        $params     = $request->only(['name', 'rate_mode', 'status', 'maintenance', 'remark']);
        $channel_id = $request->get('channel_id');

        if (empty($channel_id)) {
            return $this->fail('获取支付通道子账户失败，请尝试返回支付通道列表重新进入');
        }

        try {
            validate([
                'name'   => 'max:64',
                'remark' => 'max:1024',
            ], [
                'name.max'   => '子账户名称长度不能超过64位',
                'remark.max' => '备注长度不能超过1024位',
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if (!in_array($sort, ['id', 'rate']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = PaymentChannelAccount::select(['id', 'name', 'rate', 'status', 'maintenance', 'created_at', 'updated_at'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'name':
                        $q->where('name', 'like', "%$value%");
                        break;
                    case 'rate_mode':
                        $q->where('rate_mode', (int)$value);
                        break;
                    case 'status':
                        $q->where('status', (int)$value);
                        break;
                    case 'maintenance':
                        $q->where('maintenance', (int)$value);
                        break;
                    case 'remark':
                        $q->where('remark', 'like', "%$value%");
                        break;
                }
            }
            return $q;
        });

        // 获取总数和数据
        $total = $query->count();
        $list  = $query->skip($from)->take($limit)->orderBy($sort, $order)->get();

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
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

        $paymentChannelAccount                     = new PaymentChannelAccount();
        $paymentChannelAccount->name               = trim($param['name']);
        $paymentChannelAccount->payment_channel_id = $param['payment_channel_id'];
        $paymentChannelAccount->rate_type          = (int)($param['rate_type'] ?? 0);
        $paymentChannelAccount->rate               = (float)($param['rate'] ?? 0);
        $paymentChannelAccount->config             = $param['config'];
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

        $paymentChannelAccount->name               = trim($param['name']);
        $paymentChannelAccount->payment_channel_id = $param['payment_channel_id'];
        $paymentChannelAccount->rate_type          = (int)($param['rate_type'] ?? 0);
        $paymentChannelAccount->rate               = (float)($param['rate'] ?? 0);
        $paymentChannelAccount->config             = $param['config'];
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
