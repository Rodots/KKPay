<?php

namespace core\baseController;

use core\traits\AdminResponse;
use support\Request;

class AdminBase
{
    use AdminResponse;

    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = [];

    protected function adminLog(string $content, ?int $admin_id = null): void
    {
        $admin_id = $admin_id ?: ($this->request->AdminInfo['id'] ?? 0);
        if (empty($admin_id)) {
            return;
        }
        $row = new \app\model\AdminLog();

        $row->admin_id = $admin_id;
        $row->content = $content;
        $row->ip = get_client_ip();
        $row->user_agent = request()->header('user-agent', 'unknown');
        $row->save();
    }
}
