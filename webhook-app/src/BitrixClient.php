<?php

namespace LeadSpace\WebhookApp;

use RuntimeException;

/**
 * Клиент входящего вебхука Bitrix24 REST.
 */
class BitrixClient
{
    private string $webhookUrl;
    private int $timeout;

    public function __construct(string $webhookUrl, int $timeout = 30)
    {
        $this->webhookUrl = rtrim($webhookUrl, '/') . '/';
        $this->timeout = $timeout;
    }

    /**
     * Вызов REST-метода.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $url = $this->webhookUrl . ltrim($method, '/') . '.json';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Не удалось инициализировать cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }

        if ($raw === false || $raw === '') {
            throw new RuntimeException("Пустой ответ REST (HTTP {$httpCode})");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Некорректный JSON от REST (HTTP {$httpCode}): {$raw}");
        }

        if (isset($decoded['error'])) {
            $desc = $decoded['error_description'] ?? $decoded['error'];
            throw new RuntimeException("Bitrix REST error: {$desc}");
        }

        return $decoded;
    }

    /**
     * Пагинированный список (result + next).
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function callList(string $method, array $params = []): array
    {
        $items = [];
        $start = 0;

        do {
            $params['start'] = $start;
            $response = $this->call($method, $params);
            $batch = $response['result'] ?? [];

            if (isset($batch['tasks']) && is_array($batch['tasks'])) {
                $batch = $batch['tasks'];
            }

            if (!is_array($batch)) {
                break;
            }

            foreach ($batch as $item) {
                $items[] = $item;
            }

            $start = isset($response['next']) ? (int) $response['next'] : null;
        } while ($start !== null);

        return $items;
    }
}
