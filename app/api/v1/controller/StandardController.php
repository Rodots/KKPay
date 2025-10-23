<?php

declare(strict_types = 1);

namespace app\api\v1\controller;

use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class StandardController
{
    #[RateLimiter(limit: 1, ttl: 3, key: RateLimiter::SID, message: '状态查询频率过快，别急')]
    public function queryQRStatus(Request $request): Response
    {
        $trade_no = $request->get('trade_no');
        // 校验请求
        if ($request->expectsJson() && $request->method() === 'GET' && $request->host(true) === parse_url($request->header('referer'))['host'] && $trade_no !== null) {
            $result = [
                'code'    => 20000,
                'data'    => [],
                'message' => '测试',
                'state'   => false
            ];

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return new Response(401);
    }
}
