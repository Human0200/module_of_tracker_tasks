<?php

return [
    'portal_url' => 'https://YOUR_PORTAL.bitrix24.ru',
    'webhooks_file' => __DIR__ . '/webhooks.json',

    // userId админа для tasks.task.update
    'update_user_id' => '8',

    'poll_interval' => 60,
    'http_timeout' => 30,
    'timezone' => 'Europe/Moscow',
    'timer_prefix' => '⏱️ | ',
    'cron_key' => 'change_me',
];
