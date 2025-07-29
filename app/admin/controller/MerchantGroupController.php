<?php

declare(strict_types = 1);

namespace app\admin\controller;

use app\model\MerchantGroup;
use core\baseController\AdminBase;
use support\Request;
use support\Response;

class MerchantGroupController extends AdminBase
{
    public function index(): string
    {
        return 'MerchantGroupController/index';
    }

    /**
     * 添加商户组
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $param = $request->only(['name', 'channel', 'config']);

        if (empty($param['name']) || empty($param['channel']) || empty($param['config'])) {
            return $this->fail('必要参数缺失');
        }

        try {
            // 验证数据
            validate([
                'name|商户组名称' => 'require',
                'channel|通道配置' => 'require|array',
                'config|配置信息' => 'require|array'
            ], [
                'name.require' => '商户组名称不能为空',
                'channel.require' => '通道配置不能为空',
                'channel.array' => '通道配置必须是数组',
                'config.require' => '配置信息不能为空',
                'config.array' => '配置信息必须是数组'
            ])->check($param);
            
            // 调用模型方法创建商户组
            MerchantGroup::createMerchantGroup($param);
            
            return $this->success('添加成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 更新商户组
     *
     * @param Request $request
     * @return Response
     */
    public function update(Request $request): Response
    {
        $param = $request->only(['id', 'name', 'channel', 'config']);

        if (empty($param['id'])) {
            return $this->fail('商户组ID不能为空');
        }
        
        if (empty($param['name']) || empty($param['channel']) || empty($param['config'])) {
            return $this->fail('必要参数缺失');
        }

        if (!MerchantGroup::find($param['id'])) {
            return $this->fail('该商户组不存在');
        }

        try {
            // 验证数据
            validate([
                'name|商户组名称' => 'require',
                'channel|通道配置' => 'require|array',
                'config|配置信息' => 'require|array'
            ], [
                'name.require' => '商户组名称不能为空',
                'channel.require' => '通道配置不能为空',
                'channel.array' => '通道配置必须是数组',
                'config.require' => '配置信息不能为空',
                'config.array' => '配置信息必须是数组'
            ])->check($param);
            
            // 调用模型方法更新商户组
            MerchantGroup::updateMerchantGroup((int)$param['id'], $param);
            
            return $this->success('更新成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 删除商户组
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

        if (!$merchantGroup = MerchantGroup::find($id)) {
            return $this->fail('该商户组不存在');
        }

        try {
            $merchantGroup->delete();
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('删除成功');
    }
}
