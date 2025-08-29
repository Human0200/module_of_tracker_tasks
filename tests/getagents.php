<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

// Проверяем авторизацию в административной части
if(!$USER->IsAdmin()) {
    die('Доступ запрещен');
}

// Получаем всех агентов или агентов конкретного модуля
function getAgents($moduleId = 'leadspace.timetracker') {
    $agents = [];
    $filter = [];
    
    if(!empty($moduleId)) {
        $filter['MODULE_ID'] = $moduleId;
    }
    
    $res = \CAgent::GetList(
        ["MODULE_ID" => "ASC", "NAME" => "ASC"],
        $filter
    );
    
    while($agent = $res->Fetch()) {
        $agents[] = $agent;
    }
    
    return $agents;
}

// Выводим список агентов
function displayAgents($agents) {
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<tr>
            <th>ID</th>
            <th>Модуль</th>
            <th>Функция</th>
            <th>Интервал</th>
            <th>След. запуск</th>
            <th>Последний запуск</th>
            <th>Активность</th>
            <th>Действия</th>
          </tr>';
    
    foreach($agents as $agent) {
        echo '<tr>';
        echo '<td>'.$agent['ID'].'</td>';
        echo '<td>'.$agent['MODULE_ID'].'</td>';
        echo '<td>'.htmlspecialchars($agent['NAME']).'</td>';
        echo '<td>'.$agent['AGENT_INTERVAL'].'</td>';
        echo '<td>'.$agent['NEXT_EXEC'].'</td>';
        echo '<td>'.$agent['LAST_EXEC'].'</td>';
        echo '<td>'.($agent['ACTIVE'] == 'Y' ? 'Активен' : 'Неактивен').'</td>';
        echo '<td>
                <a href="?delete='.$agent['ID'].'" onclick="return confirm(\'Удалить агент?\')">Удалить</a> | 
                <a href="?run='.$agent['ID'].'">Запустить</a>
              </td>';
        echo '</tr>';
    }
    
    echo '</table>';
}

// Обработка действий
if(isset($_GET['delete'])) {
    \CAgent::Delete($_GET['delete']);
    LocalRedirect('/bitrix/admin/agent_check.php');
}

if(isset($_GET['run'])) {
    $agent = \CAgent::GetByID($_GET['run'])->Fetch();
    if($agent) {
        eval($agent['NAME']);
        echo '<p>Агент выполнен: '.htmlspecialchars($agent['NAME']).'</p>';
    }
}

// Получаем всех агентов или агентов конкретного модуля
$moduleId = isset($_GET['module']) ? $_GET['module'] : '';
$agents = getAgents($moduleId);

// Выводим интерфейс
echo '<h1>Проверка агентов</h1>';
echo '<form method="get">
        Фильтр по модулю: 
        <input type="text" name="module" value="'.htmlspecialchars($moduleId).'">
        <input type="submit" value="Фильтровать">
        <a href="agent_check.php">Сбросить</a>
      </form>';

echo '<p>Найдено агентов: '.count($agents).'</p>';

if(!empty($agents)) {
    displayAgents($agents);
} else {
    echo '<p>Агенты не найдены</p>';
}

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php');
?>