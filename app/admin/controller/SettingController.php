<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Config;
use Core\baseController\AdminBase;
use support\Db;
use support\Request;
use support\Response;
use Throwable;

/**
 * 站点配置管理控制器
 */
class SettingController extends AdminBase
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['frontendGet'];

    /**
     * 获取所有站点配置
     *
     * @return Response
     */
    public function index(): Response
    {
        return $this->success(data: sys_config());
    }

    /**
     * 保存所有配置
     *
     * @param Request $request
     * @return Response
     */
    public function save(Request $request): Response
    {
        $configs = $request->post('configs');

        if (empty($configs) || !is_array($configs)) {
            return $this->fail('配置数据不能为空');
        }

        Db::beginTransaction();
        try {
            foreach ($configs as $group => $items) {
                if (!is_array($items)) {
                    continue;
                }

                $group = filter($group);
                if (empty($group)) {
                    continue;
                }

                foreach ($items as $key => $value) {
                    $key = filter($key);
                    if (empty($key)) {
                        continue;
                    }

                    // 使用 upsert 进行插入或更新
                    Config::upsert(
                        [['g' => $group, 'k' => $key, 'v' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : filter((string)$value)]],
                        ['g', 'k'],
                        ['v']
                    );
                }
            }

            // 清除全局配置缓存
            if (!clear_sys_config_cache()) {
                Db::rollBack();
                return $this->fail('清除配置缓存失败');
            }

            // 记录操作日志
            $this->adminLog('更新站点配置');

            Db::commit();

            return $this->success('配置保存成功');
        } catch (Throwable $e) {
            Db::rollBack();
            return $this->fail('保存失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取前端所需的配置项
     *
     * @return Response
     */
    public function frontendGet(): Response
    {
        $data = [
            'site_name' => sys_config('system', 'site_name', '卡卡聚合支付')
        ];
        return $this->success(data: $data);
    }
}
