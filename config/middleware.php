<?php

declare(strict_types = 1);

return [
    // 全局中间件
    '' => [
        // 添加跟踪ID中间件
        App\middleware\TraceID::class,
    ],
    // 管理端中间件
    'admin' => [
        // 身份验证中间件
        App\middleware\AuthCheckAdmin::class,
    ],
];
