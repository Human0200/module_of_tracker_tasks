<?php

use Bitrix\Main\Localization\Loc;

AddEventHandler('main', 'OnBuildGlobalMenu', 'SupportMenu');

function SupportMenu(&$arGlobalMenu, &$arModuleMenu)
{
    $moduleId = 'leadspace.timetracker';

    if ($GLOBALS['APPLICATION']->GetGroupRight($moduleId) < 'R') {
        return;
    }

    // Создаем корневой раздел "Интеграция" без подпунктов
    $arGlobalMenu['support_connection'] = [
        'menu_id' => 'global_menu_support',
        'text' => Loc::getMessage('LEADSPACE_CONNECTION_TITLE'),
        'title' => Loc::getMessage('LEADSPACE_CONNECTION_DESC'),
        'sort' => 1000, // Позиция в меню
        'items_id' => 'global_menu_support_items',
        'icon' => 'update_menu_icon',
    ];

        $arGlobalMenu['support_connection']['items'][$moduleId] = [
        'menu_id' => 'menu_support_bx24',
        'text' => Loc::getMessage('LEADSPACE_MENU_TITLE'),
        'items_id' => 'menu_support_items',
        'url' => '/bitrix/admin/module_connector_admin.php?lang='.LANGUAGE_ID,
        'icon' => 'catalog_menu_icon',
    ];

}