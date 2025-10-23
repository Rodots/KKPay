<?php
return [
    'enable' => true,
    'driver' => 'auto', // auto, apcu, memory, redis
    'stores' => [
        'redis' => [
            'connection' => 'rate_limiter',
        ]
    ],
    // 这些ip的请求不做频率限制
    'ip_whitelist' => [
        '127.0.0.1',
    ],
];
