<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\PaymentChannel;
use Core\baseController\AdminBase;
use Core\Utils\PaymentGatewayUtil;
use support\Db;
use support\Request;
use support\Response;
use Throwable;

class PaymentChannelController extends AdminBase
{
    /**
     * 支付通道列表
     */
    public function index(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 20);
        $sort   = $request->get('sort', 'id');
        $order  = $request->get('order', 'desc');
        $params = $request->only(['code', 'name', 'payment_type', 'gateway', 'status']);

        try {
            validate([
                'code'    => ['max:16', 'regex' => '/^[A-Z0-9]+$/'],
                'name'    => ['max:64'],
                'gateway' => ['max:16', 'alphaNum']
            ], [
                'code.max'         => '通道编码不能超过16个字符',
                'code.regex'       => '通道编码只能包含大写字母和数字',
                'name.max'         => '通道名称不能超过64个字',
                'gateway.max'      => '网关代码不能超过16个字符',
                'gateway.alphaNum' => '网关代码只能包含字母和数字'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if (!in_array($sort, ['id', 'cost', 'rate', 'min_amount', 'max_amount', 'daily_limit', 'created_at']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }
        if ($sort === 'created_at') {
            // created_at字段在数据库中没有索引，使用id字段代替以提升查询性能
            $sort = 'id';
        }

        // 构建查询
        $query = PaymentChannel::select(['id', 'code', 'name', 'payment_type', 'gateway', 'cost', 'fixed_cost', 'rate', 'fixed_fee', 'min_amount', 'max_amount', 'daily_limit', 'status', 'created_at'])->when($params, function ($q) use ($params) {
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
        $list  = $query->offset($from)->limit($limit)->orderBy($sort, $order)->get()->append(['payment_type_text']);

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

        $query = PaymentChannel::find($id, ['id', 'code', 'name', 'payment_type', 'gateway', 'cost', 'fixed_cost', 'rate', 'fixed_fee', 'min_fee', 'max_fee', 'min_amount', 'max_amount', 'daily_limit', 'earliest_time', 'latest_time', 'diy_order_subject', 'roll_mode', 'settle_cycle', 'status', 'updated_at']);
        return $this->success(data: $query->toArray());
    }

    /**
     * 获取支付通道对接信息配置项
     */
    public function configForm(Request $request): Response
    {
        $channel_id = $request->get('channel_id');

        if (empty($channel_id)) {
            return $this->fail('获取支付通道对接信息失败，请刷新重试');
        }

        $gateway = PaymentChannel::where('id', $channel_id)->value('gateway');
        if (!$gateway) {
            return $this->fail('获取支付通道对接信息失败，请刷新重试');
        }

        // 使用工具类获取网关配置项
        $getInfo = PaymentGatewayUtil::getInfo($gateway);
        if (empty($getInfo['config'])) {
            return $this->fail('获取支付网关配置项失败，请检查该支付通道的网关代码是否正确');
        }

        return $this->success(data: [
            'config' => $getInfo['config'],
            'notes'  => $getInfo['notes'] ?? '',
        ]);
    }

    /**
     * 创建支付通道
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        try {
            $params = $this->decryptPayload($request);
            if ($params === null) {
                return $this->fail('非法请求');
            }

            validate($this->getPaymentChannelValidationRules(), $this->getPaymentChannelValidationMessages())->check($params);

            PaymentChannel::createPaymentChannel($params);
            $this->adminLog("创建支付通道【{$params['name']}】");
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
     */
    public function edit(Request $request): Response
    {
        try {
            $params = $this->decryptPayload($request);
            if ($params === null) {
                return $this->fail('非法请求');
            }

            if (empty($params['id'])) {
                return $this->fail('请求参数缺失');
            }

            if (!PaymentChannel::where('id', $params['id'])->exists()) {
                return $this->fail('该支付通道不存在');
            }

            validate($this->getPaymentChannelValidationRules(), $this->getPaymentChannelValidationMessages())->check($params);

            PaymentChannel::updatePaymentChannel((int)$params['id'], $params);
            $this->adminLog("编辑支付通道【{$params['name']}】");
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('编辑成功');
    }

    /**
     * 批量删除支付通道
     *
     * @param Request $request
     * @return Response
     */
    public function delete(Request $request): Response
    {
        $ids = $request->post('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->fail('必要参数缺失');
        }

        try {
            PaymentChannel::whereIn('id', $ids)->delete();
            $this->adminLog("批量删除支付通道，ID列表：" . json_encode($ids));
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('删除成功');
    }

    /**
     * 复制支付通道
     *
     * @param Request $request
     * @return Response
     */
    public function copy(Request $request): Response
    {
        $id     = $request->post('id');
        $number = $request->post('number', 1);

        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        if (!$row = PaymentChannel::find($id)) {
            return $this->fail('该支付通道不存在');
        }

        try {
            DB::transaction(function () use ($row, $number) {
                for ($i = 0; $i < $number; $i++) {
                    $newRow       = $row->replicate(); // 复制模型实例，排除主键、时间戳等
                    $newRow->code = $row->code . ($i + 1);
                    $newRow->save(); // 插入新记录
                }
            });
            $this->adminLog("复制支付通道【{$id}】，数量：$number");
        } catch (Throwable $e) {
            return $this->fail($e->getMessage() ?: '复制操作失败');
        }

        return $this->success('复制成功');
    }

    /**
     * 修改支付通道状态（支持批量）
     *
     * @param Request $request
     * @return Response
     */
    public function changeStatus(Request $request): Response
    {
        $ids    = $request->post('ids');
        $status = $request->post('status');

        if (empty($ids) || !is_array($ids) || !is_bool($status)) {
            return $this->fail('必要参数缺失');
        }

        try {
            PaymentChannel::whereIn('id', $ids)->update(['status' => $status]);
            $statusText = $status ? '启用' : '禁用';
            $this->adminLog("批量{$statusText}支付通道，ID列表：" . json_encode($ids));
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
            'code'              => ['require', 'max:16', 'regex' => '/^[A-Z0-9]+$/'],
            'name'              => ['require', 'max:64'],
            'payment_type'      => ['require', 'in:Alipay,WechatPay,Bank,UnionPay,QQWallet,JDPay,PayPal'],
            'gateway'           => ['require', 'max:16', 'regex' => '/^[a-zA-Z0-9]+$/'],
            'cost'              => ['require', 'float', 'between:0,100'],
            'fixed_cost'        => ['require', 'float', 'egt:0'],
            'rate'              => ['require', 'float', 'between:0,100'],
            'fixed_fee'         => ['require', 'float', 'egt:0'],
            'min_fee'           => ['float', 'egt:0'],
            'max_fee'           => ['float', 'egt:0'],
            'min_amount'        => ['float', 'egt:0'],
            'max_amount'        => ['float', 'egt:0'],
            'daily_limit'       => ['float', 'egt:0'],
            'earliest_time'     => ['regex' => '/^([01]\d|2[0-3]):([0-5]\d)$/'],
            'latest_time'       => ['regex' => '/^([01]\d|2[0-3]):([0-5]\d)$/'],
            'diy_order_subject' => ['max:255'],
            'roll_mode'         => ['number', 'egt:0'],
            'settle_cycle'      => ['integer'],
            'status'            => ['require', 'boolean'],
        ];
    }

    /**
     * 支付通道验证消息
     */
    private function getPaymentChannelValidationMessages(): array
    {
        return [
            'code.require'          => '请输入通道编码',
            'code.max'              => '通道编码不能超过16个字符',
            'code.regex'            => '通道编码只能包含大写字母和数字',
            'name.require'          => '请输入通道名称',
            'name.max'              => '通道名称不能超过64个字',
            'payment_type.require'  => '请选择支付方式',
            'payment_type.in'       => '选择的支付方式不在允许范围内',
            'gateway.require'       => '请输入网关代码',
            'gateway.max'           => '网关代码不能超过16个字符',
            'gateway.regex'         => '网关代码只能包含字母和数字',
            'cost.require'          => '请输入费率成本',
            'cost.float'            => '费率成本必须为数字',
            'cost.between'          => '费率成本须在0~100之间',
            'fixed_cost.require'    => '请输入固定成本',
            'fixed_cost.float'      => '固定成本必须为数字',
            'fixed_cost.egt'        => '固定成本不能为负数',
            'rate.require'          => '请输入费率',
            'rate.float'            => '费率必须为数字',
            'rate.between'          => '费率须在0~100之间',
            'fixed_fee.require'     => '请输入固定服务费',
            'fixed_fee.float'       => '固定服务费必须为数字',
            'fixed_fee.egt'         => '固定服务费不能为负数',
            'min_fee.float'         => '最低服务费必须为数字',
            'min_fee.egt'           => '最低服务费不能为负数',
            'max_fee.float'         => '最高服务费必须为数字',
            'max_fee.egt'           => '最高服务费不能为负数',
            'min_amount.float'      => '单笔最小金额必须为数字',
            'min_amount.egt'        => '单笔最小金额不能为负数',
            'max_amount.float'      => '单笔最大金额必须为数字',
            'max_amount.egt'        => '单笔最大金额不能为负数',
            'daily_limit.float'     => '单日限额必须为数字',
            'daily_limit.egt'       => '单日限额不能为负数',
            'earliest_time.regex'   => '最早可用时间格式不正确，须为HH:MM格式',
            'latest_time.regex'     => '最晚可用时间格式不正确，须为HH:MM格式',
            'diy_order_subject.max' => '自定义商品名称不能超过255个字',
            'roll_mode.number'      => '轮询模式必须为数字',
            'roll_mode.egt'         => '轮询模式不能为负数',
            'settle_cycle.integer'  => '结算周期必须为整数',
            'status.require'        => '请选择通道状态',
            'status.boolean'        => '通道状态值不正确',
        ];
    }
}
