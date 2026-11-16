<?php

namespace LeadSpace\WebhookApp;

use Throwable;

/**
 * Обходит задачи через несколько входящих вебхуков (админ + исполнители).
 * У каждого вебхука смотрит timerIsRunningForCurrentUser и склеивает активные таймеры.
 * Обновление TITLE — через admin-клиент (права на все задачи).
 */
class TimeTrackerService
{
    /** @var list<BitrixClient> */
    private array $clients;
    private BitrixClient $updateClient;
    private Logger $logger;
    private string $timerPrefix;

    /** @var list<string> */
    private array $timerPatterns = [
        '/⏱️\s*[\d:]*\s*\|/',
        '/\[В РАБОТЕ(\s+[\d:]+)?\]/',
        '/🔥\s*[\d:]*\s*-/',
        '/АКТИВНО(\s*\([\d:]+\))?/',
        '/\[РАБОТА(\s+[\d:]+)?\]/',
        '/⏰(\s*[\d:]+)?/',
        '/🟢(\s*[\d:]+)?/',
        '/▶️(\s*[\d:]+)?/',
    ];

    /** @var list<string> */
    private array $cleaningPatterns = [
        '/⏱️\s*[\d:]*\s*\|\s*/',
        '/\[В РАБОТЕ(\s+[\d:]+)?\]\s*/',
        '/🔥\s*[\d:]*\s*-\s*/',
        '/АКТИВНО(\s*\([\d:]+\))?\s*/',
        '/\[РАБОТА(\s+[\d:]+)?\]\s*/',
        '/⏰(\s*[\d:]+)?\s*/',
        '/🟢(\s*[\d:]+)?\s*/',
        '/▶️(\s*[\d:]+)?\s*/',
    ];

    /** @var list<string> */
    private array $titleMarkers = [
        '⏱️',
        '[В РАБОТЕ',
        '🔥',
        'АКТИВНО',
        '[РАБОТА',
        '⏰',
        '🟢',
        '▶️',
    ];

    /**
     * @param list<BitrixClient> $clients вебхуки для опроса таймеров
     * @param BitrixClient $updateClient вебхук с правами на обновление задач (обычно админ)
     */
    public function __construct(
        array $clients,
        BitrixClient $updateClient,
        Logger $logger,
        string $timerPrefix = '⏱️ | '
    ) {
        if ($clients === []) {
            throw new \InvalidArgumentException('Нужен хотя бы один webhook-клиент');
        }
        $this->clients = $clients;
        $this->updateClient = $updateClient;
        $this->logger = $logger;
        $this->timerPrefix = $timerPrefix;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pingAll(): array
    {
        $profiles = [];
        foreach ($this->clients as $i => $client) {
            try {
                $response = $client->call('profile');
                $profiles[] = [
                    'index' => $i,
                    'ok' => true,
                    'profile' => $response['result'] ?? $response,
                ];
            } catch (Throwable $e) {
                $profiles[] = [
                    'index' => $i,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $profiles;
    }

    /**
     * @return array{success:int,cleaned:int,errors:int,active:int,scanned:int,active_tasks:list<array>}
     */
    public function runOnce(): array
    {
        $scan = $this->scanAllWebhooksForActiveTimers();
        $activeTimers = $scan['active'];
        // Снимаем префикс по задачам, найденным при полном обходе
        // (фильтр TITLE %⏱️% в REST Битрикса по эмодзи не работает)
        $cleanedOpen = $this->cleanInactiveTimerTitles($activeTimers, $scan['prefixed']);
        // Отдельный проход: «ждёт контроля» (4) и «завершена» (5)
        $cleanedDone = $this->cleanPrefixedClosedTasks();
        $cleaned = $cleanedOpen + $cleanedDone;
        $marked = $this->markActiveTimerTitles($activeTimers);

        $activeList = [];
        foreach ($activeTimers as $item) {
            $activeList[] = [
                'task_id' => $item['task_id'],
                'title' => $item['title'],
                'responsible_id' => $item['user_id'],
                'webhook_user_id' => $item['webhook_user_id'],
            ];
            $this->logger->info(sprintf(
                'Таймер активен: задача #%d (webhook user %s) — %s',
                $item['task_id'],
                (string) $item['webhook_user_id'],
                $item['title']
            ));
        }

        $result = [
            'success' => $marked['success'],
            'cleaned' => $cleaned,
            'cleaned_open' => $cleanedOpen,
            'cleaned_closed' => $cleanedDone,
            'errors' => $marked['errors'],
            'active' => count($activeTimers),
            'scanned' => $scan['scanned'],
            'active_tasks' => $activeList,
        ];

        $this->logger->info(sprintf(
            'Готово: webhooks=%d, scanned=%d, active=%d, marked=%d, cleaned=%d (open=%d, closed=%d), errors=%d',
            count($this->clients),
            $result['scanned'],
            $result['active'],
            $result['success'],
            $result['cleaned'],
            $cleanedOpen,
            $cleanedDone,
            $result['errors']
        ));

        return $result;
    }

    /**
     * @return array{
     *   scanned:int,
     *   active:array<int, array{task_id:int,title:string,user_id:?int,webhook_user_id:?int}>,
     *   prefixed:array<int, string>
     * }
     */
    private function scanAllWebhooksForActiveTimers(): array
    {
        $active = [];
        $prefixed = [];
        $scanned = 0;

        foreach ($this->clients as $index => $client) {
            $webhookUserId = null;
            try {
                $profile = $client->call('profile');
                $webhookUserId = isset($profile['result']['ID']) ? (int) $profile['result']['ID'] : null;
            } catch (Throwable $e) {
                $this->logger->error("Webhook #{$index}: profile failed: " . $e->getMessage());
            }

            $this->logger->info(sprintf(
                'Сканирую задачи через webhook #%d (user %s)...',
                $index,
                $webhookUserId !== null ? (string) $webhookUserId : '?'
            ));

            $part = $this->scanClientForActiveTimers($client, $webhookUserId);
            $scanned += $part['scanned'];

            foreach ($part['active'] as $taskId => $item) {
                $active[$taskId] = $item;
            }
            foreach ($part['prefixed'] as $taskId => $title) {
                $prefixed[$taskId] = $title;
            }
        }

        $this->logger->info(sprintf(
            'Итого проверено задач: %d, с таймером: %d, с префиксом в TITLE: %d',
            $scanned,
            count($active),
            count($prefixed)
        ));

        return [
            'scanned' => $scanned,
            'active' => $active,
            'prefixed' => $prefixed,
        ];
    }

    /**
     * @return array{
     *   scanned:int,
     *   active:array<int, array{task_id:int,title:string,user_id:?int,webhook_user_id:?int}>,
     *   prefixed:array<int, string>
     * }
     */
    private function scanClientForActiveTimers(BitrixClient $client, ?int $webhookUserId): array
    {
        $active = [];
        $prefixed = [];
        $scanned = 0;
        $start = 0;

        do {
            try {
                $response = $client->call('tasks.task.list', [
                    'order' => ['ID' => 'DESC'],
                    'filter' => [
                        '!REAL_STATUS' => [4, 5],
                    ],
                    'select' => [
                        'ID',
                        'TITLE',
                        'RESPONSIBLE_ID',
                        'STATUS',
                        'REAL_STATUS',
                        'TIME_SPENT_IN_LOGS',
                        'ALLOW_TIME_TRACKING',
                    ],
                    'params' => [
                        'WITH_TIMER_INFO' => true,
                    ],
                    'start' => $start,
                ]);
            } catch (Throwable $e) {
                try {
                    $response = $client->call('tasks.task.list', [
                        'order' => ['ID' => 'DESC'],
                        'filter' => [
                            '!STATUS' => [4, 5],
                        ],
                        'select' => [
                            'ID',
                            'TITLE',
                            'RESPONSIBLE_ID',
                            'STATUS',
                            'TIME_SPENT_IN_LOGS',
                            'ALLOW_TIME_TRACKING',
                        ],
                        'params' => [
                            'WITH_TIMER_INFO' => true,
                        ],
                        'start' => $start,
                    ]);
                } catch (Throwable $e2) {
                    $this->logger->error('Ошибка tasks.task.list: ' . $e2->getMessage());
                    break;
                }
            }

            $tasks = $response['result']['tasks'] ?? $response['result'] ?? [];
            if (!is_array($tasks) || $tasks === []) {
                break;
            }

            foreach ($tasks as $task) {
                $scanned++;

                $taskId = (int) ($task['id'] ?? $task['ID'] ?? 0);
                if ($taskId <= 0) {
                    continue;
                }

                $title = (string) ($task['title'] ?? $task['TITLE'] ?? '');

                if ($title !== '' && $this->hasTimerIcon($title)) {
                    $prefixed[$taskId] = $title;
                }

                $runningFlag = $task['timerIsRunningForCurrentUser']
                    ?? $task['TIMER_IS_RUNNING_FOR_CURRENT_USER']
                    ?? 'N';

                $timeSpent = (int) ($task['timeSpentInLogs'] ?? $task['TIME_SPENT_IN_LOGS'] ?? 0);
                $timeElapsed = (int) ($task['timeElapsed'] ?? $task['TIME_ELAPSED'] ?? $timeSpent);
                $elapsedSuggestsRunning = $timeElapsed > $timeSpent;

                $isRunning = ($runningFlag === 'Y' || $runningFlag === true || $elapsedSuggestsRunning);
                if (!$isRunning) {
                    continue;
                }

                $active[$taskId] = [
                    'task_id' => $taskId,
                    'title' => $title,
                    'user_id' => isset($task['responsibleId'])
                        ? (int) $task['responsibleId']
                        : (isset($task['RESPONSIBLE_ID']) ? (int) $task['RESPONSIBLE_ID'] : null),
                    'webhook_user_id' => $webhookUserId,
                ];
            }

            $start = isset($response['next']) ? (int) $response['next'] : null;
        } while ($start !== null);

        return [
            'scanned' => $scanned,
            'active' => $active,
            'prefixed' => $prefixed,
        ];
    }

    /**
     * @param array<int, mixed> $activeTimerTasks
     * @param array<int, string> $prefixedTitles taskId => current TITLE с маркером
     */
    private function cleanInactiveTimerTitles(array $activeTimerTasks, array $prefixedTitles): int
    {
        $cleanedCount = 0;

        foreach ($prefixedTitles as $taskId => $title) {
            $taskId = (int) $taskId;
            if ($taskId <= 0 || $title === '') {
                continue;
            }

            // Таймер всё ещё активен — префикс оставляем
            if (isset($activeTimerTasks[$taskId])) {
                continue;
            }

            if (!$this->hasTimerIcon($title)) {
                continue;
            }

            $cleanTitle = $this->cleanTimerFromTitle($title);
            if ($cleanTitle === $title) {
                $this->logger->error("Не удалось очистить TITLE задачи #{$taskId}: {$title}");
                continue;
            }

            if ($this->updateTaskTitle($taskId, $cleanTitle)) {
                $this->logger->info("Снят префикс с задачи #{$taskId}: {$cleanTitle}");
                $cleanedCount++;
            }
        }

        return $cleanedCount;
    }

    /**
     * Отдельный проход: задачи в статусах «ждёт контроля» (4) и «завершена» (5)
     * с префиксом таймера в TITLE — префикс снимаем всегда.
     */
    private function cleanPrefixedClosedTasks(): int
    {
        $cleanedCount = 0;
        $scanned = 0;
        $start = 0;

        $this->logger->info('Отдельный проход: статусы 4/5 (ждёт контроля / завершена)...');

        do {
            try {
                $response = $this->updateClient->call('tasks.task.list', [
                    'order' => ['ID' => 'DESC'],
                    'filter' => [
                        'REAL_STATUS' => [4, 5],
                    ],
                    'select' => ['ID', 'TITLE', 'STATUS', 'REAL_STATUS'],
                    'start' => $start,
                ]);
            } catch (Throwable $e) {
                try {
                    $response = $this->updateClient->call('tasks.task.list', [
                        'order' => ['ID' => 'DESC'],
                        'filter' => [
                            'STATUS' => [4, 5],
                        ],
                        'select' => ['ID', 'TITLE', 'STATUS'],
                        'start' => $start,
                    ]);
                } catch (Throwable $e2) {
                    $this->logger->error('Ошибка списка закрытых задач: ' . $e2->getMessage());
                    break;
                }
            }

            $tasks = $response['result']['tasks'] ?? $response['result'] ?? [];
            if (!is_array($tasks) || $tasks === []) {
                break;
            }

            foreach ($tasks as $task) {
                $scanned++;
                $taskId = (int) ($task['id'] ?? $task['ID'] ?? 0);
                $title = (string) ($task['title'] ?? $task['TITLE'] ?? '');

                if ($taskId <= 0 || $title === '' || !$this->hasTimerIcon($title)) {
                    continue;
                }

                $cleanTitle = $this->cleanTimerFromTitle($title);
                if ($cleanTitle === $title) {
                    continue;
                }

                if ($this->updateTaskTitle($taskId, $cleanTitle)) {
                    $this->logger->info("Снят префикс с закрытой задачи #{$taskId}: {$cleanTitle}");
                    $cleanedCount++;
                }
            }

            $start = isset($response['next']) ? (int) $response['next'] : null;
        } while ($start !== null);

        $this->logger->info("Закрытые задачи: scanned={$scanned}, cleaned={$cleanedCount}");

        return $cleanedCount;
    }

    /**
     * @param array<int, array{task_id:int,title:string,user_id:?int,webhook_user_id:?int}> $activeTimerTasks
     * @return array{success:int,errors:int}
     */
    private function markActiveTimerTitles(array $activeTimerTasks): array
    {
        $success = 0;
        $errors = 0;

        foreach ($activeTimerTasks as $taskId => $timerData) {
            $title = (string) ($timerData['title'] ?? '');

            if ($title === '') {
                try {
                    $response = $this->updateClient->call('tasks.task.get', [
                        'taskId' => $taskId,
                        'select' => ['ID', 'TITLE'],
                    ]);
                    $task = $response['result']['task'] ?? $response['result'] ?? null;
                    $title = is_array($task)
                        ? (string) ($task['title'] ?? $task['TITLE'] ?? '')
                        : '';
                } catch (Throwable $e) {
                    $this->logger->error("Не удалось получить задачу #{$taskId}: " . $e->getMessage());
                    $errors++;
                    continue;
                }
            }

            if ($title === '') {
                $this->logger->error("Пустой TITLE у задачи #{$taskId}");
                $errors++;
                continue;
            }

            if ($this->hasTimerIcon($title)) {
                continue;
            }

            $newTitle = $this->timerPrefix . $title;
            if ($this->updateTaskTitle($taskId, $newTitle)) {
                $success++;
            } else {
                $errors++;
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    private function updateTaskTitle(int $taskId, string $title): bool
    {
        try {
            $this->updateClient->call('tasks.task.update', [
                'taskId' => $taskId,
                'fields' => [
                    'TITLE' => $title,
                ],
            ]);
            return true;
        } catch (Throwable $e) {
            $this->logger->error("Не удалось обновить задачу #{$taskId}: " . $e->getMessage());
            return false;
        }
    }

    private function hasTimerIcon(string $title): bool
    {
        foreach ($this->timerPatterns as $pattern) {
            if (preg_match($pattern, $title)) {
                return true;
            }
        }
        return false;
    }

    private function cleanTimerFromTitle(string $title): string
    {
        $cleanTitle = $title;
        foreach ($this->cleaningPatterns as $pattern) {
            $cleanTitle = preg_replace($pattern, '', $cleanTitle) ?? $cleanTitle;
        }
        return trim($cleanTitle);
    }
}
