<?php

return [
    'env' => env('APP_ENV', 'prod'),
    'debug' => env('APP_DEBUG', false),
    'version' => '1.1.1',
    'timezone' => 'PRC',
    'master_key' => env('MASTER_KEY'),
    'services' => [
        'pay' => ['key' => '124', 'secret' => 'abc'],
    ],
    'params' => ['manaphp_brand_show' => 1],
    'aliases' => [
    ],
    'components' => [
        'db' => env('DB_URL'),
        'redis' => env('REDIS_URL'),
        'mongodb' => env('MONGODB_URL'),
        'logger' => [
            'level' => env('LOGGER_LEVEL', 'info'),
            'appenders' => ['ManaPHP\Logger\Appender\File'],
        ],
        'translation' => ['language' => 'zh-CN,en']
    ],
    'bootstraps' => env('BOOTSTRAPS', []),
    'traces' => env('TRACES', '')
];