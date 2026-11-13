# Webhook Time Tracker

Серверное приложение: опрашивает Bitrix24 через **входящий вебхук** и синхронизирует названия задач с активными таймерами (та же логика, что у коробочного модуля).

## Быстрый старт

```bash
cd webhook-app
cp config.example.php config.php
# укажите webhook_url в config.php

php run.php --ping    # проверка profile.json
php run.php           # один цикл
php run.php --loop    # постоянный опрос
```

## Cron (раз в минуту)

```cron
* * * * * /usr/bin/php /path/to/webhook-app/run.php >> /path/to/webhook-app/logs/cron.log 2>&1
```

## Что делает

1. Опрашивает задачи: `tasks.task.list` + `WITH_TIMER_INFO`
2. Ищет активный таймер по `timerIsRunningForCurrentUser = Y`
3. Убирает маркеры `⏱️` / `[В РАБОТЕ]` у задач **без** таймера
4. Добавляет `⏱️ | ` к задачам **с** активным таймером (`tasks.task.update`)

## Важно про вебхук

Входящий вебхук работает **от имени одного пользователя** (в URL это `/rest/8/...`).
REST отдаёт активный таймер только этого пользователя.

- Нужен таймер всех сотрудников на **коробке** → папка `bitrix-module/`
- Нужен облачный опрос одного пользователя / сервисного аккаунта → эта папка

Права вебхука: достаточно scope `task` / `tasks` (запись для update).

## Конфиг

Токены пользователей — в `webhooks.json`:

```json
{
  "8": "ADMIN_TOKEN",
  "332": "USER_TOKEN"
}
```

| Ключ | Описание |
|------|----------|
| `portal_url` | `https://portal.bitrix24.ru` |
| `webhooks_file` | путь к JSON `userId => token` |
| `update_user_id` | userId админа для update TITLE |
| `poll_interval` | пауза в `--loop` (сек) |

`config.php` и `webhooks.json` в git не коммитятся.
