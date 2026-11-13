<?php

/**
 * HTTP:
 *   /timer/cron.php?key=...
 *   /timer/cron.php?key=...&ping=1
 */

declare(strict_types=1);

use LeadSpace\WebhookApp\AppBootstrap;
use LeadSpace\WebhookApp\Logger;
use LeadSpace\WebhookApp\TimeTrackerService;

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/src/BitrixClient.php';
require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/AppBootstrap.php';
require __DIR__ . '/src/TimeTrackerService.php';

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config.php missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require $configFile;

if (!empty($config['cron_key'])) {
    $key = (string) ($_GET['key'] ?? '');
    if (!hash_equals((string) $config['cron_key'], $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

date_default_timezone_set($config['timezone'] ?? 'Europe/Moscow');

$logger = new Logger(__DIR__ . '/logs/app.log', false);

try {
    $built = AppBootstrap::buildClients($config);
    $service = new TimeTrackerService(
        $built['clients'],
        $built['updateClient'],
        $logger,
        (string) ($config['timer_prefix'] ?? '⏱️ | ')
    );

    if (isset($_GET['ping'])) {
        echo json_encode(['ok' => true, 'profiles' => $service->pingAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = $service->runOnce();
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
