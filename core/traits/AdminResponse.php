<?php

declare(strict_types = 1);

namespace Core\traits;

use Core\constants\AdminRespCode;
use Core\utils\TraceIDUtil;
use support\Response;

trait AdminResponse
{
    /**
     * 返回一个成功消息的JSON格式的响应
     * @param mixed             $data
     * @param string            $message
     * @param AdminRespCode|int $code
     * @return Response
     */
    public function success(string $message = '成功', mixed $data = [], AdminRespCode|int $code = AdminRespCode::SUCCESS): Response
    {
        $result = [
            'code'    => $code,
            'data'    => $data,
            'message' => $message,
            'state'   => true
        ];
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 返回一个失败消息的JSON格式的响应
     *
     * @param string            $message
     * @param AdminRespCode|int $code
     * @param mixed             $data
     * @return Response
     */
    public function fail(string $message = '失败', AdminRespCode|int $code = AdminRespCode::FAIL, mixed $data = []): Response
    {
        $result = [
            'code'    => $code,
            'data'    => $data,
            'message' => $message,
            'state'   => false
        ];
        if ($trace_id = TraceIDUtil::getTraceID()) {
            $result['trace_id'] = $trace_id;
        }
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 返回一个异常消息的JSON格式的响应
     *
     * @param string            $message
     * @param AdminRespCode|int $code
     * @param mixed             $data
     * @return Response
     */
    public function error(string $message = '错误', AdminRespCode|int $code = AdminRespCode::ERROR, mixed $data = []): Response
    {
        $result = [
            'code'    => $code,
            'data'    => $data,
            'message' => $message,
            'state'   => false
        ];
        if ($trace_id = TraceIDUtil::getTraceID()) {
            $result['trace_id'] = $trace_id;
        }
        return new Response(500, ['Content-Type' => 'application/json'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
