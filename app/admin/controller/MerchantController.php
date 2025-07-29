<?php

declare(strict_types = 1);

namespace app\admin\controller;

use app\model\Merchant;
use app\model\MerchantGroup;
use core\baseController\AdminBase;
use support\Request;
use support\Response;

class MerchantController extends AdminBase
{
    public function index(): string
    {
        return 'MerchantController/index';
    }

    /**
     * 添加商户
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $param = $request->only(['group_id', 'email', 'phone', 'qq', 'password']);
        $param['group_id'] = $param['group_id'] ?? 1;

        if (!MerchantGroup::find($param['group_id'])) {
            return $this->fail('该商户组不存在');
        }

        try {
            validate([
                'email|邮箱'     => 'email',
                'phone|手机号码' => 'mobile',
                'qq|QQ号码'      => 'number|length:5,10',
                'password|密码'  => 'require|min:6'
            ], [
                'email.email'      => '邮箱格式不正确',
                'phone.mobile'     => '手机号码格式不正确',
                'qq.number'        => 'QQ号码格式不正确',
                'qq.length'        => 'QQ号码长度不正确',
                'password.require' => '密码不能为空',
                'password.min'     => '密码长度不能小于6位'
            ])->check($param);
            
            // 调用模型方法创建商户
            Merchant::createMerchant($param);
            
            return $this->success('添加成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 更新商户
     *
     * @param Request $request
     * @return Response
     */
    public function update(Request $request): Response
    {
        $param = $request->only(['id', 'group_id', 'email', 'phone', 'qq', 'status', 'competence']);
        
        if (empty($param['id'])) {
            return $this->fail('商户ID不能为空');
        }
        
        $param['group_id'] = $param['group_id'] ?: 1;

        if (!$user = Merchant::find($param['id'])) {
            return $this->fail('该商户不存在');
        }

        if (!MerchantGroup::find($param['group_id'])) {
            return $this->fail('该用户组不存在');
        }
        
        try {
            // 验证数据
            validate([
                'email|邮箱'     => 'email',
                'phone|手机号码' => 'mobile',
                'qq|QQ号码'      => 'number|length:5,10',
            ], [
                'email.email'      => '邮箱格式不正确',
                'phone.mobile'     => '手机号码格式不正确',
                'qq.number'        => 'QQ号码格式不正确',
                'qq.length'        => 'QQ号码长度不正确',
            ])->check($param);
            
            // 调用模型方法更新商户
            Merchant::updateMerchant((int)$param['id'], $param);
            
            return $this->success('更新成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
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
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('删除成功');
    }
}
