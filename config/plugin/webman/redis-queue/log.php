<?php

declare(strict_types = 1);

return [
    'default' => [
        'handlers' => [
            [
                'class'       => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/redis-queue/queue.log',
                    7, // $maxFiles
                ],
                'formatter'   => [
                    'class'       => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [null, 'Y-m-d H:i:s', true, true],
                ],
            ]
        ],
    ]
];
