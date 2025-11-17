<?php

declare(strict_types = 1);

namespace app\admin\controller;

use Core\baseController\AdminBase;

class IndexController extends AdminBase
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = [];

    public function index(): string
    {
        return 'index';
    }
}
