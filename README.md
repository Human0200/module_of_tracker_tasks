# Модуль трекера задач Bitrix24

Два варианта одной логики: помечать задачи с активным таймером префиксом `⏱️ | `.

## Структура

```
bitrix-module/   — модуль для коробочного Bitrix24 (агент на PHP API)
webhook-app/     — серверное приложение через входящий REST-вебхук
```

## bitrix-module

Устанавливается как модуль `leadspace.timetracker` в коробку. Агент периодически синхронизирует названия задач.

Путь установки: содержимое `bitrix-module/` → `/bitrix/modules/leadspace.timetracker/`.

## webhook-app

Отдельное CLI-приложение для облака/сервера. Опрашивает портал через входящий вебхук:

`https://portal.bitrix24.ru/rest/{user_id}/{code}/`

```bash
cd webhook-app
cp config.example.php config.php
php run.php --ping
php run.php --loop
```

Вебхук видит таймер пользователя, от которого создан. Для всех сотрудников на коробке — `bitrix-module/`.

Подробности: [webhook-app/README.md](webhook-app/README.md).
