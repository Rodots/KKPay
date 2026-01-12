<?php

declare(strict_types = 1);

/**
 * Static file settings
 */
return [
    'enable'     => true,
    'middleware' => [     // Static file Middleware
        app\middleware\StaticFile::class,
    ],
];
