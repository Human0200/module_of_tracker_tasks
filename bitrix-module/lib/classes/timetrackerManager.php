<?php
namespace LeadSpace\TimeTracker;
date_default_timezone_set('Europe/Moscow');
use CModule;
use CTasks;
use CTaskTimerManager;
use CUser;
use CTaskItem;
use Exception;

class TimeTrackerManager
{
    /**
     * Записать лог в файл (только ошибки)
     */
    private function writeLog($message)
    {
        // Логируем только ошибки
        if (strpos($message, 'ERROR') === 0) {
            $logFile = __DIR__ . '/time_tracker.txt';
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[{$timestamp}] {$message}\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Инициализация модуля задач
     */
    private function initTasksModule()
    {
        if (!CModule::IncludeModule('tasks')) {
            $this->writeLog('ERROR: Модуль "Задачи и проекты" не установлен');
            throw new Exception('Ошибка: Модуль "Задачи и проекты" не установлен');
        }
    }
    
    /**
     * Получение имени пользователя
     */
    private function getUserName($userId)
    {
        $rsUser = CUser::GetByID($userId);
        if ($user = $rsUser->Fetch()) {
            return $user['NAME'] . ' ' . $user['LAST_NAME'] . ' (' . $user['LOGIN'] . ')';
        }
        return "Пользователь не найден (ID: $userId)";
    }
    
    /**
     * Проверка наличия значков таймера в названии (без времени)
     */
    private function hasTimerIcon($title)
    {
        $timerPatterns = [
            '/⏱️\s*[\d:]*\s*\|/',           // ⏱️ | или ⏱️ 6:48 |
            '/\[В РАБОТЕ(\s+[\d:]+)?\]/',   // [В РАБОТЕ] или [В РАБОТЕ 6:48]
            '/🔥\s*[\d:]*\s*-/',            // 🔥 - или 🔥 6:48 -
            '/АКТИВНО(\s*\([\d:]+\))?/',    // АКТИВНО или АКТИВНО (6:48)
            '/\[РАБОТА(\s+[\d:]+)?\]/',     // [РАБОТА] или [РАБОТА 6:48]
            '/⏰(\s*[\d:]+)?/',              // ⏰ или ⏰ 6:48
            '/🟢(\s*[\d:]+)?/',             // 🟢 или 🟢 6:48
            '/▶️(\s*[\d:]+)?/'              // ▶️ или ▶️ 6:48
        ];
        
        foreach ($timerPatterns as $pattern) {
            if (preg_match($pattern, $title)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Очистка названия от значков таймера (включая время)
     */
    private function cleanTimerFromTitle($title)
    {
        $cleaningPatterns = [
            '/⏱️\s*[\d:]*\s*\|\s*/',        // ⏱️ | или ⏱️ 6:48 |
            '/\[В РАБОТЕ(\s+[\d:]+)?\]\s*/', // [В РАБОТЕ] или [В РАБОТЕ 6:48]
            '/🔥\s*[\d:]*\s*-\s*/',         // 🔥 - или 🔥 6:48 -
            '/АКТИВНО(\s*\([\d:]+\))?\s*/', // АКТИВНО или АКТИВНО (6:48)
            '/\[РАБОТА(\s+[\d:]+)?\]\s*/',  // [РАБОТА] или [РАБОТА 6:48]
            '/⏰(\s*[\d:]+)?\s*/',           // ⏰ или ⏰ 6:48
            '/🟢(\s*[\d:]+)?\s*/',          // 🟢 или 🟢 6:48
            '/▶️(\s*[\d:]+)?\s*/'           // ▶️ или ▶️ 6:48
        ];
        
        $cleanTitle = $title;
        foreach ($cleaningPatterns as $pattern) {
            $cleanTitle = preg_replace($pattern, '', $cleanTitle);
        }
        
        return trim($cleanTitle);
    }
    
    /**
     * Получение активных таймеров
     */
    private function getActiveTimers()
    {
        $activeTimerTasks = [];
        $usersToCheck = [];
        
        // Пробуем получить пользователей через задачи
        try {
            $rsTask = CTasks::GetList(['ID' => 'DESC'], [], ['RESPONSIBLE_ID', 'CREATED_BY']);
            while ($task = $rsTask->Fetch()) {
                if (!empty($task['RESPONSIBLE_ID']) && !in_array($task['RESPONSIBLE_ID'], $usersToCheck)) {
                    $usersToCheck[] = $task['RESPONSIBLE_ID'];
                }
                if (!empty($task['CREATED_BY']) && !in_array($task['CREATED_BY'], $usersToCheck)) {
                    $usersToCheck[] = $task['CREATED_BY'];
                }
            }
        } catch (Exception $e) {
            $this->writeLog('ERROR: Ошибка получения пользователей через задачи: ' . $e->getMessage());
        }
        
        // Если не получилось через задачи, берем активных пользователей
        if (empty($usersToCheck)) {
            try {
                $rsUsers = CUser::GetList(($by='ID'), ($order='ASC'), ['ACTIVE' => 'Y'], ['FIELDS' => ['ID']]);
                while ($user = $rsUsers->Fetch()) {
                    $usersToCheck[] = $user['ID'];
                }
            } catch (Exception $e) {
                $this->writeLog('ERROR: Ошибка получения активных пользователей: ' . $e->getMessage());
            }
        }
        
        // Проверяем активные таймеры
        foreach (array_unique($usersToCheck) as $userId) {
            if (empty($userId)) continue;
            
            try {
                $timer = CTaskTimerManager::getInstance($userId);
                $runningTask = $timer->getRunningTask();
                
                if ($runningTask && !empty($runningTask['TASK_ID'])) {
                    $activeTimerTasks[$runningTask['TASK_ID']] = [
                        'task_id' => $runningTask['TASK_ID'],
                        'user_id' => $userId,
                        'timer_info' => $runningTask
                    ];
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        return $activeTimerTasks;
    }
    
    /**
     * Очистка задач от значков таймеров через SQL
     */
    private function cleanTimerIconsViaSql($activeTimerTasks)
    {
        global $DB;
        $cleanedCount = 0;
        
        try {
            // Ищем задачи со значками таймеров
            $sql = "SELECT ID, TITLE FROM b_tasks WHERE 
                       TITLE LIKE '%⏱️%' OR 
                       TITLE LIKE '%[В РАБОТЕ%' OR 
                       TITLE LIKE '%🔥%' OR 
                       TITLE LIKE '%АКТИВНО%' OR 
                       TITLE LIKE '%[РАБОТА%' OR 
                       TITLE LIKE '%⏰%' OR 
                       TITLE LIKE '%🟢%' OR 
                       TITLE LIKE '%▶️%'";
            
            $result = $DB->Query($sql);
            $tasksToClean = [];
            
            while ($task = $result->Fetch()) {
                if ($this->hasTimerIcon($task['TITLE'])) {
                    $tasksToClean[] = $task;
                }
            }
            
            // Очищаем задачи, которые не имеют активных таймеров
            foreach ($tasksToClean as $task) {
                $taskId = $task['ID'];
                
                // Если нет активного таймера - очищаем
                if (!isset($activeTimerTasks[$taskId])) {
                    $cleanTitle = $this->cleanTimerFromTitle($task['TITLE']);
                    $result = $this->updateTask($taskId, $cleanTitle);
                    
                    if ($result['success']) {
                        $cleanedCount++;
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->writeLog('ERROR: Ошибка при очистке через SQL: ' . $e->getMessage());
        }
        
        return $cleanedCount;
    }
    
    /**
     * Обновление задачи
     */
    private function updateTask($taskId, $newTitle)
    {
        try {
            $adminUserId = 1;
            $oTaskItem = new CTaskItem($taskId, $adminUserId);

            $updateFields = array(
                "TITLE" => $newTitle,
            );

            $result = $oTaskItem->Update($updateFields);

            if ($result) {
                return ['success' => true, 'message' => 'Обновлено успешно'];
            } else {
                $this->writeLog('ERROR: Не удалось обновить задачу #' . $taskId);
                return ['success' => false, 'message' => 'Ошибка обновления задачи'];
            }
        } catch (Exception $e) {
            $this->writeLog('ERROR: Исключение при обновлении задачи #' . $taskId . ': ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Основная функция очистки и обновления названий задач
     */
    public function cleanupAndUpdateTaskTitles($returnOutput = false)
    {
        $this->initTasksModule();
        
        // Шаг 1: Поиск активных таймеров
        $activeTimerTasks = $this->getActiveTimers();
        
        // Шаг 2: Очищаем задачи от значков (передаем активные таймеры)
        $cleanedCount = $this->cleanTimerIconsViaSql($activeTimerTasks);
        
        // Шаг 3: Добавляем значки к активным задачам
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($activeTimerTasks as $taskId => $timerData) {
            $taskInfo = null;
            
            // Способ 1: через CTasks::GetList
            try {
                $rsTaskInfo = CTasks::GetList([], ['ID' => $taskId], ['ID', 'TITLE', 'STATUS', 'RESPONSIBLE_ID']);
                if ($rsTaskInfo && ($taskInfo = $rsTaskInfo->Fetch())) {
                    // Получили данные успешно
                }
            } catch (Exception $e) {
                // Попробуем другой способ
            }
            
            // Способ 2: через SQL напрямую
            if (!$taskInfo) {
                try {
                    global $DB;
                    $sql = "SELECT ID, TITLE FROM b_tasks WHERE ID = " . intval($taskId);
                    $result = $DB->Query($sql);
                    if ($task = $result->Fetch()) {
                        $taskInfo = $task;
                    }
                } catch (Exception $e) {
                    $this->writeLog('ERROR: Не удалось получить задачу #' . $taskId . ' через SQL: ' . $e->getMessage());
                }
            }
            
            // Если получили информацию о задаче
            if ($taskInfo && !empty($taskInfo['TITLE'])) {
                // Проверяем, есть ли уже значок
                if (!$this->hasTimerIcon($taskInfo['TITLE'])) {
                    $newTitle = "⏱️ | {$taskInfo['TITLE']}";
                    $result = $this->updateTask($taskId, $newTitle);
                    
                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                }
            } else {
                $this->writeLog('ERROR: Не удалось получить информацию о задаче #' . $taskId);
                $errorCount++;
            }
        }
        
        return [
            'success' => $successCount,
            'cleaned' => $cleanedCount,
            'errors' => $errorCount,
            'total' => $successCount + $cleanedCount
        ];
    }
    
    /**
     * Получить только активные таймеры (публичный метод)
     */
    public function getActiveTimersInfo()
    {
        $this->writeLog('INFO: Запрос информации об активных таймерах');
        $this->initTasksModule();
        return $this->getActiveTimers();
    }
    
    /**
     * Проверить, есть ли значки таймера в названии (публичный метод)
     */
    public function checkTimerIcon($title)
    {
        return $this->hasTimerIcon($title);
    }
    
    /**
     * Очистить название от значков (публичный метод)
     */
    public function cleanTitle($title)
    {
        return $this->cleanTimerFromTitle($title);
    }
}