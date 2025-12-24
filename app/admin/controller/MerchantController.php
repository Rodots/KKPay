<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Merchant;
use app\model\MerchantEncryption;
use app\model\MerchantLog;
use app\model\MerchantPayee;
use Core\baseController\AdminBase;
use Core\Service\OrderService;
use SodiumException;
use support\Db;
use support\Request;
use support\Response;
use support\Rodots\Crypto\XChaCha20;
use support\Rodots\JWT\JwtToken;
use Throwable;

class MerchantController extends AdminBase
{
    /**
     * 商户列表
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $params = $request->only(['merchant_number', 'email', 'mobile', 'remark', 'status', 'risk_status', 'created_at']);

        try {
            validate([
                'merchant_number' => ['startWith:M', 'alphaNum', 'length:16'],
                'email'           => ['max:64'],
                'mobile'          => ['number', 'max:11'],
                'created_at'      => ['array']
            ], [
                'merchant_number.startWith' => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.alphaNum'  => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.length'    => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'email.max'                 => '邮箱不能超过64个字符',
                'mobile.number'             => '手机号码只能包含数字',
                'mobile.max'                => '手机号码不能超过11位',
                'created_at.array'          => '创建时间范围格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 构建查询
        $query = Merchant::with('wallet:merchant_id,available_balance,unavailable_balance,margin,prepaid')->select(['id', 'merchant_number', 'email', 'mobile', 'remark', 'status', 'risk_status', 'created_at'])->when($params, function ($q) use ($params) {
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
                    case 'mobile':
                        $q->where('mobile', 'like', "%$value%");
                        break;
                    case 'remark':
                        $q->where('remark', 'like', "%$value%");
                        break;
                    case 'status':
                        $q->where('status', (bool)$value);
                        break;
                    case 'risk_status':
                        $q->where('risk_status', (bool)$value);
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
        $list  = $query->offset($from)->limit($limit)->orderByDesc('id')->get()->append(['margin']);

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 商户详情
     *
     * @param Request $request
     * @return Response
     */
    public function detail(Request $request): Response
    {
        $id = $request->get('id');

        $query = Merchant::find($id, ['id', 'merchant_number', 'email', 'mobile', 'remark', 'diy_order_subject', 'status', 'risk_status', 'competence'])->append(['margin']);
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
                'margin'   => ['require', 'float'],
                'email'    => ['email', 'max:64'],
                'mobile'   => ['mobile'],
                'password' => ['require', 'min:6']
            ], [
                'margin.require'   => '请输入保证金',
                'margin.float'     => '保证金必须为数字',
                'email.email'      => '邮箱格式不正确',
                'email.max'        => '邮箱不能超过64个字符',
                'mobile.mobile'    => '手机号码格式不正确',
                'password.require' => '请输入密码',
                'password.min'     => '密码不能少于6位'
            ])->check($params);

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
            return $this->fail('必要参数缺失');
        }

        if (!$user = Merchant::find($params['id'])) {
            return $this->fail('该商户不存在');
        }

        try {
            // 验证数据
            validate([
                'margin' => ['require', 'float'],
                'email'  => ['email', 'max:64'],
                'mobile' => ['mobile'],
            ], [
                'margin.require' => '请输入保证金',
                'margin.float'   => '保证金必须为数字',
                'email.email'    => '邮箱格式不正确',
                'email.max'      => '邮箱不能超过64个字符',
                'mobile.mobile'  => '手机号码格式不正确',
            ])->check($params);

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
        $user->status = (bool)$status;
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
        $merchant->risk_status = (bool)$status;
        if (!$merchant->save()) {
            return $this->fail('修改失败');
        }
        return $this->success('修改成功');
    }

    /**
     * 重置商户密码
     *
     * @param Request $request
     * @return Response
     */
    public function resetPassword(Request $request): Response
    {
        $id       = $request->post('id');
        $password = $request->post('password', '123456');
        if (empty($id) || empty($password)) {
            return $this->fail('必要参数缺失');
        }

        try {
            if (Merchant::resetPassword($id, $password)) {
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
     * 手动重试结算失败的订单
     *
     * @param Request $request
     * @return Response
     */
    public function retrySettlement(Request $request): Response
    {
        $id   = $request->post('id');
        $days = $request->post('days', 0);
        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        if (!Merchant::where('id', $id)->whereJsonContains('competence', 'orderSettle')->exists()) {
            return $this->fail('该商户没有结算权限');
        }

        try {
            OrderService::retryFailedSettlements($days, $id);
            $this->adminLog('手动为商户【' . $id . '】重试结算失败的订单');
        } catch (Throwable $e) {
            return $this->fail('操作失败：' . $e->getMessage());
        }

        return $this->success('执行成功');
    }

    /**
     * 批量修改商户状态
     *
     * @param Request $request
     * @return Response
     */
    public function batchChangeStatus(Request $request): Response
    {
        $ids    = $request->post('ids');
        $status = (bool)$request->post('status');

        if (empty($ids) || !is_array($ids)) {
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
     * 找回已删除的商户
     *
     * @param Request $request
     * @return Response
     */
    public function recover(Request $request): Response
    {
        $id = $request->post('id');

        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        // 查找已软删除的商户
        $merchant = Merchant::onlyTrashed()->find($id);
        if (!$merchant) {
            return $this->fail('该商户不存在或未被删除');
        }

        try {
            $merchant->restore();
            $this->adminLog("找回已删除商户【{$merchant->merchant_number}】");
        } catch (Throwable $e) {
            return $this->fail('找回失败：' . $e->getMessage());
        }

        return $this->success('找回成功');
    }

    /**
     * 获取已删除商户列表（回收站）
     *
     * @return Response
     */
    public function recycleBin(): Response
    {
        return $this->success(data: [
            'list' => Merchant::onlyTrashed()->select(['id', 'merchant_number', 'remark', 'deleted_at'])->orderByDesc('deleted_at')->get(),
        ]);
    }

    /**
     * 商户操作日志
     *
     * @param Request $request
     * @return Response
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
                'merchant_number' => ['startWith:M', 'alphaNum', 'length:16'],
                'content'         => ['max:1024'],
                'ip'              => ['max:45'],
                'created_at'      => ['array']
            ], [
                'merchant_number.startWith' => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.alphaNum'  => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'merchant_number.length'    => '商户编号格式不正确（以M开头的16位字母数字组合）',
                'content.max'               => '操作内容不能超过1024个字符',
                'ip.max'                    => 'IP地址不能超过45个字符',
                'created_at.array'          => '时间范围格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if (!in_array($sort, ['id', 'ip']) || !in_array($order, ['asc', 'desc'])) {
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
        $list  = $query->offset($from)->limit($limit)->orderBy($sort, $order)->get();

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 商户收款人列表
     *
     * @param Request $request
     * @return Response
     */
    public function payeeList(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 10);
        $params = $request->only(['merchant_number', 'created_at']);

        try {
            validate([
                'merchant_number' => ['startWith:M', 'alphaNum', 'length:16'],
                'created_at'      => ['array']
            ], [
                'merchant_number.startWith' => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.alphaNum'  => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.length'    => '商户编号是以M开头的16位数字+英文组合',
                'created_at.array'          => '请重新选择时间范围'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 构建查询
        $query = MerchantPayee::whereHas('merchant', fn($q) => $q->whereNull('deleted_at'))->with(['merchant:id,merchant_number,remark'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'merchant_number':
                        $q->where('merchant_id', Merchant::where('merchant_number', $value)->value('id'));
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
        $list  = $query->offset($from)->limit($limit)->orderByDesc('id')->get();

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 新增商户收款人
     *
     * @param Request $request
     * @return Response
     * @throws SodiumException
     */
    public function payeeCreate(Request $request): Response
    {
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        try {
            // 验证参数
            validate([
                'merchant_number' => ['require', 'startWith:M', 'alphaNum', 'length:16'],
                'payee_info'      => ['require', 'array']
            ], [
                'merchant_number.require'   => '商户编号不能为空',
                'merchant_number.startWith' => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.alphaNum'  => '商户编号是以M开头的16位数字+英文组合',
                'merchant_number.length'    => '商户编号是以M开头的16位数字+英文组合',
                'payee_info.require'        => '收款信息不能为空',
                'payee_info.array'          => '收款信息格式不正确'
            ])->check($params);

            // 验证商户是否存在
            $merchant = Merchant::where('merchant_number', $params['merchant_number'])->first();
            if (!$merchant) {
                return $this->fail('该商户不存在');
            }

            // 创建收款信息
            $payee              = new MerchantPayee();
            $payee->merchant_id = $merchant->id;
            $payee->payee_info  = $params['payee_info'];
            $payee->save();

            // 记录操作日志
            $this->adminLog("为商户【{$merchant->merchant_number}】新增收款信息");
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('新增成功');
    }

    /**
     * 编辑商户收款人
     *
     * @param Request $request
     * @return Response
     * @throws SodiumException
     */
    public function payeeEdit(Request $request): Response
    {
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        if (empty($params['id'])) {
            return $this->fail('必要参数缺失');
        }

        try {
            // 验证参数
            validate([
                'payee_info' => ['require', 'array']
            ], [
                'payee_info.require' => '收款信息不能为空',
                'payee_info.array'   => '收款信息格式不正确'
            ])->check($params);

            // 查找收款人记录
            $payee = MerchantPayee::with('merchant:id,merchant_number')->find($params['id']);
            if (!$payee) {
                return $this->fail('该收款信息不存在');
            }

            // 更新收款信息
            $payee->payee_info = $params['payee_info'];
            $payee->save();

            // 记录操作日志
            $merchant_number = $payee->merchant->merchant_number ?? '未知';
            $this->adminLog("编辑商户【{$merchant_number}】的收款信息【{$payee->id}】");
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('编辑成功');
    }

    /**
     * 删除商户收款人
     *
     * @param Request $request
     * @return Response
     */
    public function payeeDelete(Request $request): Response
    {
        $id = $request->post('id');

        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        $payee = MerchantPayee::with('merchant:id,merchant_number')->find($id);
        if (!$payee) {
            return $this->fail('该收款信息不存在');
        }

        try {
            $merchant_number = $payee->merchant->merchant_number ?? '未知';
            $payee->delete();
            // 记录操作日志
            $this->adminLog("删除商户【{$merchant_number}】的收款信息【{$id}】");
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('删除成功');
    }

    /**
     * 获取商户收款人详情
     *
     * @param Request $request
     * @return Response
     */
    public function payeeDetail(Request $request): Response
    {
        $id = $request->get('id');

        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        $payee = MerchantPayee::with(['merchant:id,merchant_number,remark'])->find($id);
        if (!$payee) {
            return $this->fail('该收款信息不存在');
        }

        return $this->success(data: $payee->toArray());
    }

    /**
     * 根据商户ID获取收款人列表
     *
     * @param Request $request
     * @return Response
     */
    public function payeeListByMerchant(Request $request): Response
    {
        $merchant_id = $request->get('merchant_id');

        if (empty($merchant_id)) {
            return $this->fail('必要参数缺失');
        }

        // 验证商户是否存在
        if (!Merchant::where('id', $merchant_id)->exists()) {
            return $this->fail('该商户不存在');
        }

        $list = MerchantPayee::select(['id', 'payee_info', 'is_default'])->where('merchant_id', $merchant_id)->orderByDesc('id')->get();

        return $this->success(data: ['list' => $list]);
    }

    /**
     * 设置商户默认收款人
     *
     * @param Request $request
     * @return Response
     */
    public function payeeSetDefault(Request $request): Response
    {
        $id = $request->post('id');

        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        if (!$payee = MerchantPayee::with('merchant:id,merchant_number')->find($id, ['id', 'merchant_id'])) {
            return $this->fail('该收款信息不存在');
        }

        try {
            Db::beginTransaction();

            // 将该商户所有收款人设为非默认
            MerchantPayee::where('merchant_id', $payee->merchant_id)->update(['is_default' => false]);

            // 设置当前收款人为默认
            MerchantPayee::where('id', $payee->id)->update(['is_default' => true]);

            Db::commit();

            // 记录操作日志
            $merchant_number = $payee->merchant->merchant_number ?? '未知';
            $this->adminLog("设置商户【{$merchant_number}】的默认收款人为【{$id}】");
        } catch (Throwable $e) {
            Db::rollBack();
            return $this->fail($e->getMessage());
        }

        return $this->success('设置成功');
    }

    /**
     * 获取商户密钥详情
     *
     * @param Request $request
     * @return Response
     */
    public function encryptionDetail(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        if (!$merchant = Merchant::with('encryption:merchant_id,mode,hash_key,aes_key,rsa2_key')->find($id, ['id', 'merchant_number'])) {
            return $this->fail('该商户不存在');
        }

        $encryption = $merchant->encryption;
        if (!$encryption) {
            return $this->fail('该商户密钥配置不存在');
        }

        return $this->success(data: [
            'merchant_number' => $merchant->merchant_number,
            'mode'            => $encryption->mode,
            'mode_text'       => MerchantEncryption::MODE_TEXT_MAP[$encryption->mode] ?? '未知',
            'hash_key'        => $encryption->hash_key,
            'aes_key'         => $encryption->aes_key,
            'rsa2_key'        => $encryption->rsa2_key
        ]);
    }

    /**
     * 修改商户密钥配置
     *
     * @param Request $request
     * @return Response
     * @throws SodiumException
     */
    public function encryptionEdit(Request $request): Response
    {
        $payload = $request->post('payload');
        if (empty($payload)) {
            return $this->fail('非法请求');
        }

        $params = new XChaCha20(config('kkpay.api_crypto_key', ''))->get($payload);

        if (empty($params['id'])) {
            return $this->fail('必要参数缺失');
        }

        // 验证模式是否有效
        if (!in_array($params['mode'] ?? '', MerchantEncryption::SUPPORTED_MODES)) {
            return $this->fail('对接模式无效');
        }

        // 验证 hash_key 长度（如果提供）
        if (!empty($params['hash_key']) && strlen($params['hash_key']) !== 32) {
            return $this->fail('散列算法对接密钥必须为32位字符串');
        }

        // 验证 aes_key 长度（如果提供）
        if (!empty($params['aes_key']) && strlen($params['aes_key']) !== 32) {
            return $this->fail('AES加密传输密钥必须为32位字符串');
        }

        if (!$merchant = Merchant::find($params['id'])) {
            return $this->fail('该商户不存在');
        }

        $encryption = MerchantEncryption::find($params['id']);
        if (!$encryption) {
            return $this->fail('该商户密钥配置不存在');
        }

        try {
            // 构建更新数据
            $update_data = ['mode' => $params['mode']];
            if (!empty($params['hash_key'])) {
                $update_data['hash_key'] = $params['hash_key'];
            }
            if (!empty($params['aes_key'])) {
                $update_data['aes_key'] = $params['aes_key'];
            }

            $encryption->fill($update_data)->save();

            // 记录操作日志
            $this->adminLog("修改商户【{$merchant->merchant_number}】的密钥配置");
        } catch (Throwable $e) {
            return $this->fail('修改失败：' . $e->getMessage());
        }

        return $this->success('修改成功');
    }

    /**
     * 生成商户 RSA2 密钥对
     *
     * @param Request $request
     * @return Response
     */
    public function encryptionGenerateRsa2(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        $merchant = Merchant::find($id, ['id', 'merchant_number']);
        if (!$merchant) {
            return $this->fail('该商户不存在');
        }

        try {
            $keys = MerchantEncryption::generateRsa2KeyPair($merchant->id);

            // 记录操作日志
            $this->adminLog("为商户【{$merchant->merchant_number}】生成RSA2密钥对");
        } catch (Throwable $e) {
            return $this->fail('生成失败：' . $e->getMessage());
        }

        return $this->success('生成成功，请妥善保存私钥', [
            'private_key' => $keys['private_key']
        ]);
    }
}
