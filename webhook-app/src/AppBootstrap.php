<?php

namespace LeadSpace\WebhookApp;

/**
 * Сборка клиентов из config.php + webhooks.json (userId => token).
 */
class AppBootstrap
{
    /**
     * @param array<string, mixed> $config
     * @return array{clients:list<BitrixClient>,updateClient:BitrixClient}
     */
    public static function buildClients(array $config, ?string $baseDir = null): array
    {
        $timeout = (int) ($config['http_timeout'] ?? 30);
        $baseDir = $baseDir ?? dirname(__DIR__);
        $urls = [];

        $portal = rtrim((string) ($config['portal_url'] ?? 'https://leadspace.bitrix24.ru'), '/');
        $tokens = self::loadWebhookTokens($config, $baseDir);

        foreach ($tokens as $userId => $token) {
            $userId = trim((string) $userId);
            $token = trim((string) $token);
            if ($userId === '' || $token === '') {
                continue;
            }
            $urls[] = "{$portal}/rest/{$userId}/{$token}/";
        }

        // Совместимость со старым форматом полных URL
        if (!empty($config['webhook_url']) && is_string($config['webhook_url'])) {
            $urls[] = $config['webhook_url'];
        }
        if (!empty($config['webhooks']) && is_array($config['webhooks'])) {
            foreach ($config['webhooks'] as $url) {
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }

        $urls = array_values(array_unique(array_map(
            static fn(string $u): string => rtrim($u, '/') . '/',
            $urls
        )));

        if ($urls === []) {
            throw new \RuntimeException('Нет вебхуков: заполните webhooks.json (userId => token)');
        }

        $clients = [];
        foreach ($urls as $url) {
            $clients[] = new BitrixClient($url, $timeout);
        }

        $updateUserId = (string) ($config['update_user_id'] ?? array_key_first($tokens) ?? '');
        $updateUrl = $config['update_webhook_url'] ?? null;

        if (!is_string($updateUrl) || $updateUrl === '') {
            if ($updateUserId !== '' && isset($tokens[$updateUserId])) {
                $updateUrl = "{$portal}/rest/{$updateUserId}/{$tokens[$updateUserId]}/";
            } elseif ($updateUserId !== '' && isset($tokens[(int) $updateUserId])) {
                $updateUrl = "{$portal}/rest/{$updateUserId}/{$tokens[(int) $updateUserId]}/";
            } else {
                $updateUrl = $urls[0];
            }
        }

        return [
            'clients' => $clients,
            'updateClient' => new BitrixClient($updateUrl, $timeout),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string> userId => token
     */
    private static function loadWebhookTokens(array $config, string $baseDir): array
    {
        $path = (string) ($config['webhooks_file'] ?? ($baseDir . '/webhooks.json'));
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Некорректный JSON в {$path}");
        }

        $tokens = [];
        foreach ($data as $userId => $token) {
            if (is_string($token) || is_numeric($token)) {
                $tokens[(string) $userId] = (string) $token;
            }
        }

        return $tokens;
    }
}
