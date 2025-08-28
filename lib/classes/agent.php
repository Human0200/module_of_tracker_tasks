<?php
namespace LeadSpace\AgentFunctions;

use LeadSpace\TimeTracker\TimeTrackerManager;
use Exception;

class Agent
{
    public static function run()
    {
        try {
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