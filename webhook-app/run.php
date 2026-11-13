#!/usr/bin/env php
<?php

/**
 * CLI:
 *   php run.php
 *   php run.php --loop
 *   php run.php --ping
 */

declare(strict_types=1);

use LeadSpace\WebhookApp\AppBootstrap;
use LeadSpace\WebhookApp\Logger;
use LeadSpace\WebhookApp\TimeTrackerService;

require __DIR__ . '/src/BitrixClient.php';
require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/AppBootstrap.php';
require __DIR__ . '/src/TimeTrackerService.php';

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Нет config.php. Скопируйте config.example.php → config.php.\n");
    exit(1);
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Europe/Moscow');

$args = array_slice($argv, 1);
$loop = in_array('--loop', $args, true);
$ping = in_array('--ping', $args, true);

$logger = new Logger(__DIR__ . '/logs/app.log', true);
$built = AppBootstrap::buildClients($config);
$service = new TimeTrackerService(
    $built['clients'],
    $built['updateClient'],
    $logger,
    (string) ($config['timer_prefix'] ?? '⏱️ | ')
);

try {
    if ($ping) {
        foreach ($service->pingAll() as $row) {
            $logger->info('Webhook: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
        }
        exit(0);
    }

    do {
        $service->runOnce();

        if ($loop) {
            $interval = max(5, (int) ($config['poll_interval'] ?? 60));
            $logger->info("Следующий опрос через {$interval} сек...");
            sleep($interval);
        }
    } while ($loop);
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    exit(1);
}
