<?php

declare(strict_types = 1);

namespace app\admin\controller;

use core\baseController\AdminBase;
use support\Request;

class IndexController extends AdminBase
{
    public function index(Request $request)
    {
        return $this->success();
    }
}
