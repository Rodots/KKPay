<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Admin;
use app\model\AdminLog;
use Core\baseController\AdminBase;
use support\Request;
use support\Response;
use Throwable;

class AdminController extends AdminBase
{
    /**
     * 管理员列表
     */
    public function index(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 20);
        $sort   = $request->get('sort', 'id');
        $order  = $request->get('order', 'desc');
        $params = $request->only(['role', 'account', 'nickname', 'email', 'status', 'created_at']);

        try {
            validate([
                'account'    => ['max:32'],
                'nickname'   => ['max:16'],
                'email'      => ['max:64'],
                'created_at' => ['array']
            ], [
                'account.max'      => '账号不能超过32个字符',
                'nickname.max'     => '昵称不能超过16个字',
                'email.max'        => '邮箱不能超过64个字符',
                'created_at.array' => '创建时间范围格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if (!in_array($sort, ['id', 'role']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = Admin::select(['id', 'role', 'account', 'nickname', 'email', 'status', 'totp_secret', 'created_at', 'updated_at'])->where('role', '>', $this->getRole())->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'role':
                        $q->where('role', (int)$value);
                        break;
                    case 'account':
                        $q->where('account', $value);
                        break;
                    case 'email':
                        $q->where('email', 'like', "%$value%");
                        break;
                    case 'status':
                        $q->where('status', (int)$value);
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
        $list  = $query->offset($from)->limit($limit)->orderBy($sort, $order)->get()->append(['role_name', 'totp_state']);

        return $this->success(data: [
            'list'  => $list,
            'total' => $total,
        ]);
    }

    /**
     * 管理员详情
     */
    public function detail(Request $request): Response
    {
        $id = $request->get('id');

        $query = Admin::find($id, ['id', 'role', 'account', 'nickname', 'email', 'status']);
        return $this->success(data: $query->toArray());
    }

    /**
     * 创建管理员
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

            validate([
                'role'     => ['require', 'gt:' . $this->getRole()],
                'account'  => ['require', 'max:32'],
                'nickname' => ['require', 'max:16'],
                'email'    => ['email'],
                'password' => ['require', 'min:5']
            ], [
                'role.require'     => '请选择角色',
                'role.gt'          => '只能创建比自己权限低的角色',
                'account.require'  => '请输入账号',
                'account.max'      => '账号不能超过32个字符',
                'nickname.require' => '请输入昵称',
                'nickname.max'     => '昵称不能超过16个字',
                'email.email'      => '邮箱格式不正确',
                'password.require' => '请输入密码',
                'password.min'     => '密码不能少于5位'
            ])->check($params);

            Admin::createAdmin($params);
            $this->adminLog("创建管理员【{$params['account']}】");
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('创建成功');
    }

    /**
     * 编辑管理员
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

            if (!Admin::where('id', $params['id'])->exists()) {
                return $this->fail('该管理员不存在');
            }

            validate([
                'role'         => ['require', 'gt:' . $this->getRole()],
                'account'      => ['require', 'max:32'],
                'nickname'     => ['require', 'max:16'],
                'email'        => ['email'],
                'new_password' => ['min:5']
            ], [
                'role.require'     => '请选择角色',
                'role.gt'          => '只能修改为比自己权限低的角色',
                'account.require'  => '请输入账号',
                'account.max'      => '账号不能超过32个字符',
                'nickname.require' => '请输入昵称',
                'nickname.max'     => '昵称不能超过16个字',
                'email.email'      => '邮箱格式不正确',
                'new_password.min' => '新密码不能少于5位'
            ])->check($params);

            Admin::updateAdmin($params['id'], $params);
            $this->adminLog("编辑管理员【{$params['account']}】信息");
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('编辑成功');
    }

    /**
     * 快捷修改管理员状态
     *
     * @param Request $request
     * @return Response
     */
    public function changeStatus(Request $request): Response
    {
        $id     = $request->post('id');
        $status = $request->post('status');
        if (empty($id) || !in_array($status, [0, 1], true)) {
            return $this->fail('必要参数缺失');
        }
        if (!$user = Admin::find($id)) {
            return $this->fail('该管理员不存在');
        }
        $user->status = $status;
        if (!$user->save()) {
            return $this->fail('修改失败');
        }
        $statusText = $status ? '启用' : '禁用';
        $this->adminLog("{$statusText}管理员【{$user->account}】");
        return $this->success('修改成功');
    }

    /**
     * 重置管理员密码
     *
     * @param Request $request
     * @return Response
     */
    public function resetPassword(Request $request): Response
    {
        $id   = $request->post('id');
        $type = $request->post('type');
        if (empty($id) || !in_array($type, ['login', 'fund'])) {
            return $this->fail('必要参数缺失');
        }

        try {
            if (Admin::resetPassword($id, $type)) {
                $account  = Admin::where('id', $id)->value('account');
                $typeName = $type === 'login' ? '登录' : '资金';
                $this->adminLog("重置管理员【{$account}】{$typeName}密码");
                return $this->success('重置成功');
            }
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->fail('重置失败');
    }

    /**
     * 重置管理员TOTP密钥
     *
     * @param Request $request
     * @return Response
     */
    public function resetTotp(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return $this->fail('必要参数缺失');
        }

        try {
            if (Admin::resetTotp($id)) {
                $account = Admin::where('id', $id)->value('account');
                $this->adminLog("重置管理员【{$account}】TOTP密钥");
                return $this->success('重置成功');
            }
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->fail('重置失败');
    }

    /**
     * 批量修改管理员状态
     * @param Request $request
     * @return Response
     */
    public function batchChangeStatus(Request $request): Response
    {
        $ids    = $request->post('ids');
        $status = (int)$request->post('status');

        if (empty($ids) || !is_array($ids)) {
            return $this->fail('必要参数缺失');
        }

        try {
            Admin::whereIn('id', $ids)->update(['status' => $status]);
            $statusText = $status ? '启用' : '禁用';
            $this->adminLog("批量{$statusText}管理员，ID列表：" . json_encode($ids));
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('修改成功');
    }

    /**
     * 管理员操作日志
     */
    public function log(Request $request): Response
    {
        $from   = $request->get('from', 0);
        $limit  = $request->get('limit', 20);
        $sort   = $request->get('sort', 'id');
        $order  = $request->get('order', 'desc');
        $params = $request->only(['account', 'content', 'ip', 'created_at']);

        try {
            validate([
                'account'    => 'max:32',
                'content'    => 'max:1024',
                'ip'         => 'max:45',
                'created_at' => 'array'
            ], [
                'account.max'      => '账号不能超过32个字符',
                'content.max'      => '操作内容不能超过1024个字符',
                'ip.max'           => 'IP地址不能超过45个字符',
                'created_at.array' => '时间范围格式不正确'
            ])->check($params);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 检测要排序的字段是否在允许的字段列表中并检测排序顺序是否正确
        if (!in_array($sort, ['id', 'admin_id', 'ip']) || !in_array($order, ['asc', 'desc'])) {
            return $this->fail('排序失败，请刷新后重试');
        }

        // 构建查询
        $query = AdminLog::with(['admin:id,account'])->when($params, function ($q) use ($params) {
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                switch ($key) {
                    case 'account':
                        $q->where('admin_id', Admin::where('account', $value)->value('id'));
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
}
