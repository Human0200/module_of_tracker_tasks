<?php

namespace LeadSpace\AgentFunctions;

use Exception;
use Bitrix\Main\Loader;
use LeadSpace\TimeTracker\TimeTrackerManager;

class Agent
{
    public static function run()
    {
        try {
            if (!Loader::includeModule('leadspace.timetracker') || !Loader::includeModule('crm')) {
                return __METHOD__ . '(1);';
            }
            // Создаем экземпляр TimeTrackerManager
            $timeTracker = new TimeTrackerManager();

            // Выполняем синхронизацию названий задач с таймерами
            $result = $timeTracker->cleanupAndUpdateTaskTitles(true);

            return "\\LeadSpace\\AgentFunctions\\Agent::run();"; // Возвращаем строку для повторного запуска агента

        } catch (Exception $e) {
            return "\\LeadSpace\\AgentFunctions\\Agent::run();"; // Продолжаем работу несмотря на ошибку
        }
    }
}
