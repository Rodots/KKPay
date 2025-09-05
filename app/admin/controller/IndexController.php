<?php

declare(strict_types = 1);

namespace App\admin\controller;

use App\model\Admin;
use Core\baseController\AdminBase;
use support\Request;

class IndexController extends AdminBase
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index'];

    public function index(Request $request)
    {
        $trade_no = '';
        for ($i = 0; $i < 10000; $i++) {
            $now = microtime(true);
            $seconds = (int)$now;
            $micros  = (int)(($now - $seconds) * 1000000); // 取微秒级后6位
            // 组合：业务类型(1) + 时间(14) + 微秒(6) + 随机纯数字(5) + 随机字母(2) = 28位
            $trade_no = 'P' . date('YmdHis', $seconds) . str_pad((string)$micros, 6, '0', STR_PAD_LEFT) . random(5, 'num') . random(2, 'upper');
        }
        return $trade_no;

        $row           = new Admin();
        $row->account  = 'cdd';
        $row->salt     = random(4);
        $row->password = password_hash($row->salt . hash('xxh128', '123456') . 'kkpay', PASSWORD_BCRYPT);
        $row->save();
        return $this->success(data: $row);
    }
}
