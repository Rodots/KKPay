<?php

declare(strict_types = 1);

namespace app\admin\controller;

use app\model\PaymentChannel;
use core\baseController\AdminBase;
use SodiumException;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use Throwable;

class PaymentChannelController extends AdminBase
{
    /**
     * 支付通道列表
     */
    public function index(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $sort   = $request->get('sort', 'id');
        $order  = $request->get('order', 'desc');
        $params = $request->only(['code', 'name', 'payment_type', 'gateway', 'status']);

        try {
            validate([
                'code'    => 'max:16|alphaNum|upper',
                'name'    => 'max:64',
                'gateway' => 'max:16|alphaNum'
            ], [
                'code.max'         => '通道编码长度不能超过16位',
                'code.alphaNum'    => '通道编码只能是大写字母和数字',
                'code.upper'       => '通道编码只能是大写字母和数字',
                'name.max'         => '通道名称长度不能超过64位',
                'gateway.max'      => '网关代码长度不能超过16位',
                'gateway|alphaNum' => '网关代码只能是字母和数字'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if (!in_array($sort, ['id', 'costs', 'rate', 'created_at']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = PaymentChannel::select(['id', 'code', 'name', 'payment_type', 'gateway', 'costs', 'fixed_costs', 'rate', 'fixed_fee', 'status', 'created_at'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'code':
                        $q->where('code', $value);
                        break;
                    case 'name':
                        $q->where('name', 'like', "%$value%");
                        break;
                    case 'gateway':
                        $q->where('gateway', $value);
                        break;
                    case 'payment_type':
                        $q->where('payment_type', $value);
                        break;
                    case 'status':
                        $q->where('status', (int)$value);
                        break;
                }
            }
            return $q;
        });

        // 获取总数和数据
        $total = $query->count();
        $list  = $query->skip($from)->take($limit)->orderBy($sort, $order)->get()->append(['payment_type_text']);

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 支付通道详情
     */
    public function detail(Request $request): Response
    {
        $id = $request->get('id');

        $query = PaymentChannel::find($id, ['id', 'code', 'name', 'payment_type', 'gateway', 'costs', 'fixed_costs', 'rate', 'fixed_fee', 'min_fee', 'max_fee', 'min_amount', 'max_amount', 'daily_limit', 'earliest_time', 'latest_time', 'roll_mode', 'settle_cycle', 'status', 'updated_at']);
        return $this->success(data: $query->toArray());
    }

    /**
     * 创建支付通道
     *
     * @param Request $request
     * @return Response
     * @throws SodiumException
     */
    public function create(Request $request): Response
    {
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        try {
            validate($this->getPaymentChannelValidationRules(), $this->getPaymentChannelValidationMessages())->check($params);

            // 调用模型方法创建商户
            PaymentChannel::createPaymentChannel($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('创建成功');
    }

    /**
     * 编辑支付通道
     *
     * @param Request $request
     * @return Response
     * @throws SodiumException
     */
    public function edit(Request $request): Response
    {
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        if (empty($params['id'])) {
            return $this->fail('请求参数缺失');
        }

        if (!PaymentChannel::where('id', $params['id'])->exists()) {
            return $this->fail('该支付通道不存在');
        }

        try {
            validate($this->getPaymentChannelValidationRules(), $this->getPaymentChannelValidationMessages())->check($params);

            // 调用模型方法更新商户
            PaymentChannel::updatePaymentChannel((int)$params['id'], $params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('编辑成功');
    }

    /**
     * 删除支付通道
     *
     * @param Request $request
     * @return Response
     */
    public function delete(Request $request): Response
    {
        $id = $request->post('id');

        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        if (!$user = PaymentChannel::find($id)) {
            return $this->fail('该支付通道不存在');
        }

        try {
            $user->delete();
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('删除成功');
    }

    /**
     * 快捷修改支付通道状态
     *
     * @param Request $request
     * @return Response
     */
    public function changeStatus(Request $request): Response
    {
        $id     = $request->post('id');
        $status = $request->post('status');

        // 检查参数是否为布尔值或可转换为布尔值
        if (empty($id) || !is_bool($status)) {
            return $this->fail('必要参数缺失');
        }

        if (!$channel = PaymentChannel::find($id)) {
            return $this->fail('该支付通道不存在');
        }

        // 确保 status 是布尔值
        $channel->status = $status;

        if (!$channel->save()) {
            return $this->fail('修改失败');
        }

        return $this->success('修改成功');
    }

    /**
     * 批量修改支付通道状态
     * @param Request $request
     * @return Response
     */
    public function batchChangeStatus(Request $request): Response
    {
        $ids    = $request->post('ids');
        $status = $request->post('status');

        // 检查参数是否为布尔值或可转换为布尔值
        if (empty($ids) || !is_array($ids) || !is_bool($status)) {
            return $this->fail('必要参数缺失');
        }

        try {
            // 确保 status 是布尔值
            PaymentChannel::whereIn('id', $ids)->update(['status' => $status]);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('修改成功');
    }


    /**
     * 支付通道验证规则
     */
    private function getPaymentChannelValidationRules(): array
    {
        return [
            'code'          => ['require', 'max:16', 'regex' => '/^[A-Z0-9]+$/'],
            'name'          => ['require', 'max:64'],
            'payment_type'  => ['require', 'in:Alipay,WechatPay,Bank,UnionPay,QQWallet,JDPay,PayPal'],
            'gateway'       => ['require', 'max:16', 'regex' => '/^[a-zA-Z0-9]+$/'],
            'costs'         => ['require', 'float', 'between:0,100'],
            'fixed_costs'   => ['require', 'float', 'egt:0'],
            'rate'          => ['require', 'float', 'between:0,100'],
            'fixed_fee'     => ['require', 'float', 'egt:0'],
            'min_fee'       => ['float', 'egt:0'],
            'max_fee'       => ['float', 'egt:0'],
            'min_amount'    => ['float', 'egt:0'],
            'max_amount'    => ['float', 'egt:0'],
            'daily_limit'   => ['float', 'egt:0'],
            'earliest_time' => ['regex' => '/^([01]\d|2[0-3]):([0-5]\d)$/'],
            'latest_time'   => ['regex' => '/^([01]\d|2[0-3]):([0-5]\d)$/'],
            'roll_mode'     => ['integer', 'egt:0'],
            'settle_cycle'  => ['integer', 'egt:0'],
            'status'        => ['require', 'boolean'],
        ];
    }

    /**
     * 支付通道验证消息
     */
    private function getPaymentChannelValidationMessages(): array
    {
        return [
            'code.require'         => '通道编码不能为空',
            'code.max'             => '通道编码长度不能超过16位',
            'code.regex'           => '通道编码只能由大写字母和数字组成',
            'name.require'         => '通道名称不能为空',
            'name.max'             => '通道名称长度不能超过64位',
            'payment_type.require' => '支付方式不能为空',
            'payment_type.in'      => '支付方式不在允许范围内',
            'gateway.require'      => '网关代码不能为空',
            'gateway.max'          => '网关代码长度不能超过16位',
            'gateway.regex'        => '网关代码只能由字母和数字组成',
            'costs.require'        => '费率成本不能为空',
            'costs.float'          => '费率成本必须是数字',
            'costs.between'        => '费率成本必须在0到100%之间',
            'fixed_costs.require'  => '固定成本不能为空',
            'fixed_costs.float'    => '固定成本必须是数字',
            'fixed_costs.egt'      => '固定成本不能为负数',
            'rate.require'         => '费率不能为空',
            'rate.float'           => '费率必须是数字',
            'rate.between'         => '费率必须在0到100%之间',
            'fixed_fee.require'    => '固定手续费不能为空',
            'fixed_fee.float'      => '固定手续费必须是数字',
            'fixed_fee.egt'        => '固定手续费不能为负数',
            'min_fee.float'        => '最低手续费必须是数字',
            'min_fee.egt'          => '最低手续费不能为负数',
            'max_fee.float'        => '最高手续费必须是数字',
            'max_fee.egt'          => '最高手续费不能为负数',
            'min_amount.float'     => '单笔最小金额必须是数字',
            'min_amount.egt'       => '单笔最小金额不能为负数',
            'max_amount.float'     => '单笔最大金额必须是数字',
            'max_amount.egt'       => '单笔最大金额不能为负数',
            'daily_limit.float'    => '单日收款限额必须是数字',
            'daily_limit.egt'      => '单日收款限额不能为负数',
            'earliest_time.regex'  => '最早可用时间格式不正确，应为 HH:MM 格式',
            'latest_time.regex'    => '最晚可用时间格式不正确，应为 HH:MM 格式',
            'roll_mode.integer'    => '轮询模式必须是整数',
            'roll_mode.egt'        => '轮询模式不能为负数',
            'settle_cycle.integer' => '结算周期必须是整数',
            'settle_cycle.egt'     => '结算周期不能为负数',
            'status.require'       => '请选择状态',
            'status.boolean'       => '请选择状态',
        ];
    }
}
