<?php

declare(strict_types = 1);

namespace App\middleware;

use Core\utils\TraceIDUtil;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class TraceID implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 从请求头中获取跟踪ID，如果没有则生成一个新的
        $traceId = $request->header('X-Trace-ID') ?: TraceIDUtil::generateTraceID();

        // 设置到Context中
        TraceIDUtil::setTraceID($traceId);

        // 处理请求
        $response = $handler($request);

        // 将跟踪ID添加到响应头中
        $response->withHeaders([
            'X-Powered-By' => 'KKPay',
            'X-Trace-ID'   => $traceId
        ]);

        return $response;
    }
}
