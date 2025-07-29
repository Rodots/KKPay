<?php

declare(strict_types = 1);

namespace core\traits;

use core\constants\ApiRespCode;
use core\utils\TraceIDUtil;
use support\Response;

trait ApiResponse
{
    /**
     * 返回一个成功消息的JSON格式的响应
     * @param mixed           $data
     * @param string          $message
     * @param ApiRespCode|int $code
     * @return Response
     */
    public function success(mixed $data = [], string $message = '成功', ApiRespCode|int $code = ApiRespCode::SUCCESS): Response
    {
        $result = [
            'message'  => $message,
            'code'     => $code,
            'data'     => $data,
            'state'    => true,
            'datetime' => date('Y-m-d H:i:s')
        ];
        if (empty($data)) {
            unset($result['data']);
        }
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 返回一个失败消息的JSON格式的响应
     *
     * @param string          $message
     * @param ApiRespCode|int $code
     * @param mixed           $data
     * @return Response
     */
    public function fail(string $message = '失败', ApiRespCode|int $code = ApiRespCode::FAIL, mixed $data = []): Response
    {
        $result = [
            'state'   => false,
            'code'    => $code,
            'message' => $message,
            'data'    => $data
        ];
        if (empty($data)) {
            unset($result['data']);
        }
        if ($trace_id = TraceIDUtil::getTraceID()) {
            $result['trace_id'] = $trace_id;
        }
        return new Response(400, ['Content-Type' => 'application/json'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 返回一个异常消息的JSON格式的响应
     *
     * @param string          $message
     * @param ApiRespCode|int $code
     * @param mixed           $data
     * @return Response
     */
    public function error(string $message = '错误', ApiRespCode|int $code = ApiRespCode::ERROR, mixed $data = []): Response
    {
        $result = [
            'state'   => false,
            'code'    => $code,
            'message' => $message,
            'data'    => $data
        ];
        if (empty($data)) {
            unset($result['data']);
        }
        if ($trace_id = TraceIDUtil::getTraceID()) {
            $result['trace_id'] = $trace_id;
        }
        return new Response(500, ['Content-Type' => 'application/json'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
