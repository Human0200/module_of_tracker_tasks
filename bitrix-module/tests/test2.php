<?php
use Bitrix\Main\Loader;
use LeadSpace\AgentFunctions\Agent;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
Loader::includeModule('leadspace.timetracker');
$result = Agent::run();
echo '<pre>';
print_r($result);
echo '</pre>';