<?php

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('leadspace.timetracker', [
    'Settings24\GlobalSettings' => 'lib/classes/settings.php',
    'LeadSpace\TimeTracker\TimeTrackerManager' => 'lib/classes/timetrackerManager.php',
    'LeadSpace\AgentFunctions\Agent' => 'lib/classes/agent.php',

]);
?>