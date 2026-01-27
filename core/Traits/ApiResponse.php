<?php

declare(strict_types=1);

namespace Core\Traits;

use Core\Constants\ApiRespCode;
use Core\Utils\TraceIDUtil;
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
            'state'     => true,
            'message'   => $message,
            'code'      => $code,
            'data'      => $data,
            'timestamp' => time()
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
        if (request()->isGet()) {
            return $this->pageMsg($message);
        }

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

    /**
     * 返回一个带消息的HTML单页视图响应
     *
     * @param string $message
     * @return Response
     */
    public function pageMsg(string $message = '错错错，是我的错，请你再试一遍吧~',): Response
    {
        $backButtonHtml = !detectMobileApp() ? '<a href="javascript:history.back()" class="back-button">返回上一页</a>' : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>页面提示</title>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 40px;
            text-align: center;
            max-width: 512px;
            width: 75vw;
        }
        .title {
            font-size: 24px;
            color: #2c3e50;
            margin: 20px 0;
        }
        .message {
            color: #7f8c8d;
            font-size: 16px;
            margin-bottom: 30px;
        }
        .back-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }
        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="title">页面提示</h1>
        <p class="message">$message</p>
        {$backButtonHtml}
    </div>
</body>
</html>
HTML;
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache'], $html);
    }

    /**
     * 返回一个带消息的纯文本响应
     *
     * @param string $message
     * @return Response
     */
    public function textMsg(string $message = '错错错，是我的错，请你再试一遍吧~'): Response
    {
        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-cache'], $message);
    }
}
