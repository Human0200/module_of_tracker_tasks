<?php
namespace LeadSpace\TimeTracker;

use Settings24\GlobalSettings;
use CModule;
use CTasks;
use CTaskTimerManager;
use CUser;
use Exception;

class TimeTrackerManager
{
    /**
     * Инициализация модуля задач
     */
    private function initTasksModule()
    {
        if (!CModule::IncludeModule('tasks')) {
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
     * Форматирование времени
     */
    private function formatTime($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        } else {
            return sprintf('%d:%02d', $minutes, $secs);
        }
    }
    
    /**
     * Проверка наличия значков таймера в названии
     */
    private function hasTimerIcon($title)
    {
        $timerPatterns = [
            '/⏱️\s*[\d:]+\s*\|/',           // ⏱️ 6:48 |
            '/\[В РАБОТЕ\s+[\d:]+\]/',      // [В РАБОТЕ 6:48]
            '/🔥\s*[\d:]+\s*-/',            // 🔥 6:48 -
            '/АКТИВНО\s*\([\d:]+\)/',       // АКТИВНО (6:48)
            '/\[РАБОТА\s+[\d:]+\]/',        // [РАБОТА 6:48]
            '/⏰\s*[\d:]+/',                 // ⏰ 6:48
            '/🟢\s*[\d:]+/',                // 🟢 6:48
            '/▶️\s*[\d:]+/'                 // ▶️ 6:48
        ];
        
        foreach ($timerPatterns as $pattern) {
            if (preg_match($pattern, $title)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Очистка названия от значков таймера
     */
    private function cleanTimerFromTitle($title)
    {
        $cleaningPatterns = [
            '/⏱️\s*[\d:]+\s*\|\s*/',        // ⏱️ 6:48 |
            '/\[В РАБОТЕ\s+[\d:]+\]\s*/',   // [В РАБОТЕ 6:48]
            '/🔥\s*[\d:]+\s*-\s*/',         // 🔥 6:48 -
            '/АКТИВНО\s*\([\d:]+\)\s*/',    // АКТИВНО (6:48)
            '/\[РАБОТА\s+[\d:]+\]\s*/',     // [РАБОТА 6:48]
            '/⏰\s*[\d:]+\s*/',              // ⏰ 6:48
            '/🟢\s*[\d:]+\s*/',             // 🟢 6:48
            '/▶️\s*[\d:]+\s*/'              // ▶️ 6:48
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
        
        // Получаем всех пользователей с задачами
        $rsTask = CTasks::GetList(['ID' => 'DESC'], [], ['RESPONSIBLE_ID', 'CREATED_BY']);
        while ($task = $rsTask->Fetch()) {
            if (!in_array($task['RESPONSIBLE_ID'], $usersToCheck)) {
                $usersToCheck[] = $task['RESPONSIBLE_ID'];
            }
            if (!in_array($task['CREATED_BY'], $usersToCheck)) {
                $usersToCheck[] = $task['CREATED_BY'];
            }
        }
        
        // Удаляем дубликаты и проверяем активные таймеры
        $usersToCheck = array_unique($usersToCheck);
        
        foreach ($usersToCheck as $userId) {
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
     * Получение задач со значками таймеров
     */
    private function getTasksWithTimerIcons()
    {
        $tasksWithTimerIcons = [];
        
        // Ищем все задачи
        $rsAllTasks = CTasks::GetList(
            ['ID' => 'DESC'],
            [], // без фильтра - все задачи
            ['ID', 'TITLE', 'STATUS', 'RESPONSIBLE_ID', 'ALLOW_TIME_TRACKING']
        );
        
        while ($task = $rsAllTasks->Fetch()) {
            if ($this->hasTimerIcon($task['TITLE'])) {
                $tasksWithTimerIcons[] = $task;
            }
        }
        
        return $tasksWithTimerIcons;
    }
    
    /**
     * Обновление задачи
     */
    private function updateTask($taskId, $newTitle)
    {
        try {
            $taskObj = new CTasks();
            $updateResult = $taskObj->Update($taskId, ['TITLE' => $newTitle]);
            
            if ($updateResult) {
                return ['success' => true, 'message' => 'Обновлено успешно'];
            } else {
                return ['success' => false, 'message' => $taskObj->LAST_ERROR];
            }
        } catch (Exception $e) {
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
        
        // Шаг 2: Поиск задач со значками таймеров
        $tasksWithTimerIcons = $this->getTasksWithTimerIcons();
        
        // Шаг 3: Анализ действий
        $toClean = [];      // Задачи для очистки (нет активного таймера)
        $toUpdate = [];     // Задачи для обновления времени (есть активный таймер)
        
        foreach ($tasksWithTimerIcons as $task) {
            $taskId = $task['ID'];
            
            if (isset($activeTimerTasks[$taskId])) {
                // Есть активный таймер - нужно обновить время
                $toUpdate[] = [
                    'task' => $task,
                    'timer' => $activeTimerTasks[$taskId]
                ];
            } else {
                // Нет активного таймера - нужно очистить
                $toClean[] = $task;
            }
        }
        
        // Шаг 4: Проверка активных задач без значков
        $toAdd = []; // Активные задачи без значков
        
        foreach ($activeTimerTasks as $taskId => $timerData) {
            // Проверяем, есть ли эта задача уже в списке для обновления
            $alreadyHasIcon = false;
            foreach ($toUpdate as $updateItem) {
                if ($updateItem['task']['ID'] == $taskId) {
                    $alreadyHasIcon = true;
                    break;
                }
            }
            
            if (!$alreadyHasIcon) {
                // Получаем информацию о задаче
                $rsTaskInfo = CTasks::GetList([], ['ID' => $taskId], ['ID', 'TITLE', 'STATUS', 'RESPONSIBLE_ID']);
                if ($taskInfo = $rsTaskInfo->Fetch()) {
                    $toAdd[] = [
                        'task' => $taskInfo,
                        'timer' => $timerData
                    ];
                }
            }
        }
        
        // Шаг 5: Выполнение операций
        $successCount = 0;
        $errorCount = 0;
        
        // 5.1. Очищаем задачи без активных таймеров
        foreach ($toClean as $task) {
            $taskId = $task['ID'];
            $cleanTitle = $this->cleanTimerFromTitle($task['TITLE']);
            
            $result = $this->updateTask($taskId, $cleanTitle);
            
            if ($result['success']) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }
        
        // 5.2. Обновляем время в активных задачах
        foreach ($toUpdate as $item) {
            $task = $item['task'];
            $timer = $item['timer'];
            $taskId = $task['ID'];
            
            $cleanTitle = $this->cleanTimerFromTitle($task['TITLE']);
            $currentTime = $this->formatTime($timer['timer_info']['RUN_TIME']);
            $newTitle = "⏱️ {$currentTime} | {$cleanTitle}";
            
            $result = $this->updateTask($taskId, $newTitle);
            
            if ($result['success']) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }
        
        // 5.3. Добавляем значки к активным задачам
        foreach ($toAdd as $item) {
            $task = $item['task'];
            $timer = $item['timer'];
            $taskId = $task['ID'];
            
            $currentTime = $this->formatTime($timer['timer_info']['RUN_TIME']);
            $newTitle = "⏱️ {$currentTime} | {$task['TITLE']}";
            
            $result = $this->updateTask($taskId, $newTitle);
            
            if ($result['success']) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }
        
        return [
            'success' => $successCount,
            'errors' => $errorCount,
            'total' => count($toClean) + count($toUpdate) + count($toAdd)
        ];
    }
    
    /**
     * Получить только активные таймеры (публичный метод)
     */
    public function getActiveTimersInfo()
    {
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