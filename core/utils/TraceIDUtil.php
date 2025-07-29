<?php

declare(strict_types = 1);

namespace core\utils;

use Webman\Context;

class TraceIDUtil
{
    /**
     * 获取当前请求的跟踪ID
     * 
     * @return string|null
     */
    public static function getTraceID(): ?string
    {
        return Context::get('trace_id');
    }

    /**
     * 设置当前请求的跟踪ID
     * 
     * @param string $traceId
     * @return void
     */
    public static function setTraceID(string $traceId): void
    {
        Context::set('trace_id', $traceId);
    }

    /**
     * 生成一个新的跟踪ID
     *
     * @return string
     */
    public static function generateTraceID(): string
    {
        return uniqid('req_', true);
    }
}
