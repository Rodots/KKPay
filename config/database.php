<?php

declare(strict_types = 1);

return [
    'default'     => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => '127.0.0.1',
            'port'      => '3306',
            'database'  => 'kkpay',
            'username'  => 'root',
            'password'  => 'root',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix'    => 'kkpay_',
            'strict'    => true,
            'engine'    => null,
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false, // Must be false for Swoole and Swow drivers.
            ],
            'pool'      => [
                'max_connections'    => 5,
                'min_connections'    => 1,
                'wait_timeout'       => 3,
                'idle_timeout'       => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];
