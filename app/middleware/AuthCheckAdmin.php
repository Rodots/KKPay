<?php

declare(strict_types = 1);

namespace app\middleware;

use core\constants\AdminRespCode;
use core\traits\AdminResponse;
use ReflectionClass;
use support\Rodots\JWT\JwtToken;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class AuthCheckAdmin implements MiddlewareInterface
{
    use AdminResponse;

    public function process(Request $request, callable $handler): Response
    {
        // 获取请求头中的Authorization字段
        $authorization = $request->header('Authorization');
        if (!empty($authorization) && JwtToken::getInstance()->validate($authorization)) {
            // 已经登录，并且Token有效，请求继续向洋葱芯穿越
            return $handler($request);
        }

        // 通过反射获取控制器哪些方法不需要登录
        try {
            $controller = new ReflectionClass($request->controller);
        } catch (\ReflectionException $e) {
            return $this->fail('[系统维护]' . $e->getMessage(), AdminRespCode::ERROR);
        }
        $noNeedLogin = $controller->getDefaultProperties()['noNeedLogin'] ?? [];

        // 访问的方法需要登录
        if (!in_array($request->action, $noNeedLogin)) {
            // 拦截请求，返回一个重定向响应，请求停止向洋葱芯穿越
            return $this->fail('请先登录', AdminRespCode::NOT_LOGIN);
        }

        // 不需要登录，请求继续向洋葱芯穿越
        return $handler($request);
    }
}
