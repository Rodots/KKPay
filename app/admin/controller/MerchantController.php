<?php

declare(strict_types = 1);

namespace app\admin\controller;

use app\model\Merchant;
use app\model\MerchantLog;
use core\baseController\AdminBase;
use SodiumException;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use support\Rodots\JWT\JwtToken;
use Throwable;

class MerchantController extends AdminBase
{
    /**
     * 商户列表
     */
    public function index(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $params = $request->only(['merchant_number', 'email', 'phone', 'remark', 'status', 'risk_status']);

        try {
            validate([
                'merchant_number' => 'alphaNum|startWith:M|length:24',
                'email'           => 'max:64',
                'phone'           => 'number|max:11',
                'created_at'      => 'array'
            ], [
                'merchant_number.alphaNum'  => '商户编号是以M开头的24位英文+数字',
                'merchant_number.startWith' => '商户编号是以M开头的24位英文+数字',
                'merchant_number.length'    => '商户编号是以M开头的24位英文+数字',
                'email.max'                 => '邮箱长度不能超过64位',
                'phone.number'              => '手机号码只能是纯数字',
                'phone.max'                 => '手机号码长度不能超过11位',
                'created_at.array'          => '请重新选择选择时间范围'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 构建查询
        $query = Merchant::select(['id', 'merchant_number', 'email', 'phone', 'remark', 'status', 'risk_status', 'created_at', 'updated_at'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'merchant_number':
                        $q->where('merchant_number', trim($value));
                        break;
                    case 'email':
                        $q->where('email', 'like', "%$value%");
                        break;
                    case 'phone':
                        $q->where('phone', 'like', "%$value%");
                        break;
                    case 'remark':
                        $q->where('remark', 'like', "%$value%");
                        break;
                    case 'status':
                        $q->where('status', (int)$value);
                        break;
                    case 'risk_status':
                        $q->where('risk_status', (int)$value);
                        break;
                }
            }
            return $q;
        });

        // 获取总数和数据
        $total = $query->count();
        $list  = $query->skip($from)->take($limit)->orderByDesc('id')->get();

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 商户详情
     */
    public function detail(Request $request): Response
    {
        $id = $request->get('id');

        $query = Merchant::find($id, ['id', 'merchant_number', 'email', 'phone', 'remark', 'diy_order_subject', 'status', 'risk_status', 'competence']);
        return $this->success(data: $query->toArray());
    }

    /**
     * 创建商户
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
            validate([
                'email'    => 'email',
                'phone'    => 'mobile',
                'password' => 'require|min:6'
            ], [
                'email.email'      => '邮箱格式不正确',
                'phone.mobile'     => '手机号码格式不正确',
                'password.require' => '密码不能为空',
                'password.min'     => '密码长度不能小于6位'
            ])->check($params);

            // 调用模型方法创建商户
            Merchant::createMerchant($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('创建成功');
    }

    /**
     * 编辑商户
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

        if (!$user = Merchant::find($params['id'])) {
            return $this->fail('该商户不存在');
        }

        try {
            // 验证数据
            validate([
                'email' => 'email',
                'phone' => 'mobile',
            ], [
                'email.email'  => '邮箱格式不正确',
                'phone.mobile' => '手机号码格式不正确',
            ])->check($params);

            // 调用模型方法更新商户
            Merchant::updateMerchant($user->id, $params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('编辑成功');
    }

    /**
     * 删除商户
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

        if (!$user = Merchant::find($id)) {
            return $this->fail('该商户不存在');
        }

        try {
            $user->delete();
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('删除成功');
    }

    /**
     * 快捷修改商户状态
     *
     * @param Request $request
     * @return Response
     */
    public function changeStatus(Request $request): Response
    {
        $id     = $request->post('id');
        $status = $request->post('status');
        if (empty($id) || !in_array($status, [0, 1])) {
            return $this->fail('必要参数缺失');
        }
        if (!$user = Merchant::find($id)) {
            return $this->fail('该商户不存在');
        }
        $user->status = (int)$status;
        if (!$user->save()) {
            return $this->fail('修改失败');
        }
        return $this->success('修改成功');
    }

    /**
     * 快捷修改商户风控状态
     *
     * @param Request $request
     * @return Response
     */
    public function changeRiskStatus(Request $request): Response
    {
        $id     = $request->post('id');
        $status = $request->post('risk_status');
        if (empty($id) || !in_array($status, [0, 1])) {
            return $this->fail('必要参数缺失');
        }
        if (!$merchant = Merchant::find($id)) {
            return $this->fail('该商户不存在');
        }
        $merchant->risk_status = (int)$status;
        if (!$merchant->save()) {
            return $this->fail('修改失败');
        }
        return $this->success('修改成功');
    }

    /**
     * 重置商户密码为123456
     *
     * @param Request $request
     * @return Response
     */
    public function resetPassword(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        try {
            if (Merchant::resetPassword($id)) {
                return $this->success('重置成功');
            }
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->fail('重置失败');
    }

    /**
     * 模拟登录商户
     *
     * @param Request $request
     * @return Response
     */
    public function simulateLogin(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        $adminId = $request->AdminInfo['id'];
        $row     = Merchant::find($id);
        try {
            // 生成JWT令牌
            $ext   = ['request_simulate_admin_id' => $adminId, 'merchant_id' => $row->id];
            $token = JwtToken::getInstance()->generate($ext);

            // 记录登录成功日志
            $this->adminLog('模拟登录【' . $row->merchant_number . '】商户', $adminId);
        } catch (Throwable $e) {
            return $this->error('模拟登录失败：' . $e->getMessage());
        }

        // 返回登录成功响应
        return $this->success('模拟登录成功', [
            'account' => $row->merchant_number,
            'token'   => $token,
            'avatar'  => 'https://weavatar.com/avatar/' . hash('sha256', $row->email ?: '2854203763@qq.com') . '?d=mp',
            'email'   => $row->email
        ]);
    }

    /**
     * 批量修改商户状态
     * @param Request $request
     * @return Response
     */
    public function batchChangeStatus(Request $request): Response
    {
        $ids    = $request->post('ids');
        $status = (int)$request->post('status');

        if (empty($ids) || !is_array($ids) || !in_array($status, [0, 1])) {
            return $this->fail('必要参数缺失');
        }

        try {
            Merchant::whereIn('id', $ids)->update(['status' => $status]);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('修改成功');
    }

    /**
     * 商户操作日志
     */
    public function log(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $sort   = $request->get('sort', 'id');
        $order  = $request->get('order', 'desc');
        $params = $request->only(['merchant_number', 'content', 'ip', 'created_at']);

        try {
            validate([
                'merchant_number' => 'alphaNum|startWith:M|length:24',
                'content'         => 'max:1024',
                'ip'              => 'max:45',
                'created_at'      => 'array'
            ], [
                'merchant_number.alphaNum'  => '商户编号是以M开头的24位英文+数字',
                'merchant_number.startWith' => '商户编号是以M开头的24位英文+数字',
                'merchant_number.length'    => '商户编号是以M开头的24位英文+数字',
                'content.max'               => '操作内容不能超过1024个字符',
                'ip.max'                    => '操作IP长度不能超过45位',
                'created_at.array'          => '请重新选择选择时间范围'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if (!in_array($sort, ['id', 'merchant_id', 'ip']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = MerchantLog::with(['merchant:id,merchant_number'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'merchant_number':
                        $q->where('merchant_id', Merchant::where('merchant_number', $value)->value('id'));
                        break;
                    case 'content':
                        $q->where('content', 'like', '%' . $value . '%');
                        break;
                    case 'ip':
                        $q->where('ip', $value);
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
        $list  = $query->skip($from)->take($limit)->orderBy($sort, $order)->get();

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }
}
