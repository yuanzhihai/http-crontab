<?php
return [
    // 定时器名称
    'name'     => 'Http Crontab Server',
    // debug模式
    'debug'    => false,
    // socket 上下文选项
    'context'  => [],
    //定时器安全秘钥
    'safe_key' => env('safe_key', 'Q85gb1ncuWDsZTVoAEvymrNHhaRtp73M'),
    //定时器请求地址
    'base_url' => env('base_url', 'http://127.0.0.1:2345'),
    // 数据表
    'table'    => [
        // 任务表
        'task'     => 'crontab_task',
        // 任务日志表
        'task_log' => 'crontab_task_log',
    ]
];