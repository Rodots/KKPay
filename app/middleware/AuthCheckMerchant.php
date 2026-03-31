<?php

declare(strict_types=1);

namespace app\middleware;

use Core\Constants\AdminRespCode;
use Core\Traits\AdminResponse;
use ReflectionClass;
use ReflectionException;
use support\Rodots\JWT\Exception\JwtTokenException;
use support\Rodots\JWT\JwtToken;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 商户端身份认证中间件
 *
 * 从 JWT Token 中解析 merchant_id，注入到 $request->MerchantInfo。
 * 支持控制器通过 $noNeedLogin 属性声明免登录方法。
 */
class AuthCheckMerchant implements MiddlewareInterface
{
    use AdminResponse;

    public function process(Request $request, callable $handler): Response
    {
        // 先检查当前访问的方法是否需要登录
        try {
            $controller  = new ReflectionClass($request->controller);
            $noNeedLogin = $controller->getDefaultProperties()['noNeedLogin'] ?? [];
        } catch (ReflectionException $e) {
            return $this->fail('[系统维护]' . $e->getMessage(), AdminRespCode::ERROR);
        }

        // 如果访问的方法不需要登录，则直接继续处理请求
        if (in_array($request->action, (array)$noNeedLogin)) {
            return $handler($request);
        }

        // 需要登录的方法，验证Token
        $authorization = $request->header('Authorization');

        // 如果不存在Authorization头，返回未登录错误
        if (empty($authorization)) {
            return $this->fail('请先登录', AdminRespCode::INVALID_TOKEN);
        }

        // 验证Token
        try {
            // 解析Token获取商户信息
            $merchantId = JwtToken::getInstance()->getExtendVal($authorization, 'merchant_id');

            // 设置商户信息到请求对象
            $merchantInfo          = $request->MerchantInfo ?? [];
            $merchantInfo['id']    = $merchantId;
            $request->MerchantInfo = $merchantInfo;

            // Token验证通过，继续处理请求
            return $handler($request);
        } catch (JwtTokenException $e) {
            return $this->fail($e->getMessage(), AdminRespCode::INVALID_TOKEN);
        }
    }
}
