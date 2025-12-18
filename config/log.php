<?php

declare(strict_types = 1);

return [
    'default'    => [
        'handlers'   => [
            [
                'class'       => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [runtime_path() . '/logs/kkpay.log'],
                'formatter'   => [
                    'class'       => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [null, 'Y-m-d H:i:s', true, true],
                ],
            ]
        ],
        'processors' => [
            function (Monolog\LogRecord $record): Monolog\LogRecord {
                $traceId = Core\Utils\TraceIDUtil::getTraceID();
                if ($traceId !== null) {
                    $record->offsetSet('extra', ['trace_id' => $traceId]);
                }
                return $record;
            }
        ],
    ],
    'pay_notify' => [
        'handlers' => [
            [
                'class'       => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [runtime_path() . '/logs/notify_logs/notify.log', 30, 200, true, null, false, 'Y-m-d', '{date}'],
                'formatter'   => [
                    'class'       => Monolog\Formatter\LineFormatter::class,
                    'constructor' => ["[%datetime%] %message% %context%\n", 'H:i:s', true, true],
                ],
            ]
        ]
    ],
];
