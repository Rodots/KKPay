<?php

declare(strict_types = 1);

namespace app\admin\controller;

use app\model\PaymentChannel;
use app\model\PaymentChannelAccount;
use Core\baseController\AdminBase;
use SodiumException;
use support\Db;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
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
        $params     = $request->only(['name', 'inherit_config', 'status', 'maintenance', 'remark']);
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
        if (!in_array($sort, ['id', 'min_amount', 'max_amount', 'daily_limit', 'rate']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = PaymentChannelAccount::with('paymentChannel:id,rate,min_amount,max_amount,daily_limit')->select(['id', 'name', 'payment_channel_id', 'inherit_config', 'rate', 'min_amount', 'max_amount', 'daily_limit', 'status', 'maintenance', 'remark', 'created_at', 'updated_at'])->where('payment_channel_id', $channel_id)->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'name':
                        $q->where('name', 'like', "%$value%");
                        break;
                    case 'inherit_config':
                        $q->where('inherit_config', (bool)$value);
                        break;
                    case 'status':
                        $q->where('status', (bool)$value);
                        break;
                    case 'maintenance':
                        $q->where('maintenance', (bool)$value);
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
        $list  = $query->skip($from)->limit($limit)->orderBy($sort, $order)->get();

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 支付通道子账户详情
     */
    public function detail(Request $request): Response
    {
        $id = $request->get('id');

        $query = PaymentChannelAccount::find($id, ['id', 'name', 'payment_channel_id', 'inherit_config', 'rate', 'min_amount', 'max_amount', 'daily_limit', 'earliest_time', 'latest_time', 'config', 'status', 'remark', 'updated_at']);
        return $this->success(data: $query->toArray());
    }

    /**
     * 创建支付通道子账户
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

        if (empty($params['payment_channel_id'])) {
            return $this->fail('请求参数缺失');
        }

        if (!PaymentChannel::where('id', $params['payment_channel_id'])->exists()) {
            return $this->fail('关联的支付通道不存在，请尝试返回后重试');
        }

        try {
            validate($this->getPaymentChannelAccountValidationRules(), $this->getPaymentChannelAccountValidationMessages())->check($params);

            PaymentChannelAccount::createPaymentChannelAccount($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('创建成功');
    }

    /**
     * 编辑支付通道子账户
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

        if (empty($params['id']) || empty($params['payment_channel_id'])) {
            return $this->fail('请求参数缺失');
        }

        if (!PaymentChannel::where('id', $params['payment_channel_id'])->exists()) {
            return $this->fail('关联的支付通道不存在，请尝试返回后重试');
        }

        try {
            validate($this->getPaymentChannelAccountValidationRules(), $this->getPaymentChannelAccountValidationMessages())->check($params);

            PaymentChannelAccount::updatePaymentChannelAccount((int)$params['id'], $params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('编辑成功');
    }

    /**
     * 删除支付通道子账户
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

        if (!$row = PaymentChannelAccount::find($id)) {
            return $this->fail('该支付通道子账户不存在');
        }

        try {
            $row->delete();
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('删除成功');
    }

    /**
     * 复制支付通道子账户
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

        if (!$row = PaymentChannelAccount::find($id)) {
            return $this->fail('该支付通道子账户不存在');
        }

        try {
            DB::transaction(function () use ($row, $number) {
                for ($i = 0; $i < $number; $i++) {
                    $newAccount = $row->replicate(); // 复制模型实例，排除主键、时间戳等
                    $newAccount->save(); // 插入新记录
                }
            });
        } catch (Throwable $e) {
            return $this->fail($e->getMessage() ?: '复制操作失败');
        }

        return $this->success('复制成功');
    }

    /**
     * 快捷修改支付通道子账户状态
     *
     * @param Request $request
     * @return Response
     */
    public function changeStatus(Request $request): Response
    {
        $id     = $request->post('id');
        $status = $request->post('status');
        $field  = $request->post('field', 'status');

        // 检查参数是否为布尔值
        if (empty($id) || !is_bool($status)) {
            return $this->fail('必要参数缺失');
        }

        if (!$channel = PaymentChannelAccount::find($id)) {
            return $this->fail('该子账户不存在');
        }

        $channel->{$field} = $status;

        if (!$channel->save()) {
            return $this->fail('修改失败');
        }

        return $this->success('修改成功');
    }

    /**
     * 支付通道子账户验证规则
     */
    private function getPaymentChannelAccountValidationRules(): array
    {
        return [
            'name'           => ['require', 'max:64'],
            'inherit_config' => ['require', 'boolean'],
            'rate'           => ['float', 'between:0,100'],
            'min_amount'     => ['float', 'egt:0'],
            'max_amount'     => ['float', 'egt:0'],
            'daily_limit'    => ['float', 'egt:0'],
            'earliest_time'  => ['regex' => '/^([01]\d|2[0-3]):([0-5]\d)$/'],
            'latest_time'    => ['regex' => '/^([01]\d|2[0-3]):([0-5]\d)$/'],
            'config'         => ['require', 'array'],
            'status'         => ['require', 'boolean'],
            'remark'         => ['max:1024'],
        ];
    }

    /**
     * 支付通道子账户验证消息
     */
    private function getPaymentChannelAccountValidationMessages(): array
    {
        return [
            'name.require'           => '通道名称不能为空',
            'name.max'               => '通道名称长度不能超过64位',
            'inherit_config.require' => '请选择是否继承配置',
            'inherit_config.boolean' => '请选择是否继承配置',
            'rate.float'             => '费率必须是数字',
            'rate.between'           => '费率必须在0到100%之间',
            'min_amount.float'       => '单笔最小金额必须是数字',
            'min_amount.egt'         => '单笔最小金额不能为负数',
            'max_amount.float'       => '单笔最大金额必须是数字',
            'max_amount.egt'         => '单笔最大金额不能为负数',
            'daily_limit.float'      => '单日收款限额必须是数字',
            'daily_limit.egt'        => '单日收款限额不能为负数',
            'earliest_time.regex'    => '最早可用时间格式不正确，应为 HH:MM 格式',
            'latest_time.regex'      => '最晚可用时间格式不正确，应为 HH:MM 格式',
            'config.require'         => '对接信息不得为空',
            'config.array'           => '对接信息校验不通过',
            'status.require'         => '请选择状态',
            'status.boolean'         => '状态值必须是布尔值',
            'remark.max'             => '备注长度不能超过1024位',
        ];
    }
}
