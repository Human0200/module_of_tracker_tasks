<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    exit();
}

use Bitrix\Main\Localization\Loc;

if (!Bitrix\Main\Loader::includeModule('main')) {
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
    echo 'Не удалось подключить модуль "main"';
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';
    exit();
}

Loc::loadMessages(__FILE__);

global $USER;
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

$APPLICATION->SetTitle(Loc::getMessage('TECH_SUPPORT_TITLE'));
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
?>

<div class="adm-detail-content">
    <div class="adm-detail-content-item-block">
        <div style="padding: 20px; max-width: 800px; margin: 0 auto;">
            <h2><?= Loc::getMessage('TECH_SUPPORT_HEADER') ?></h2>
            
            <div class="adm-info-message">
                <p><?= Loc::getMessage('TECH_SUPPORT_DESCRIPTION') ?></p>
            </div>
            
            <div class="adm-detail-content-item-block" style="margin-top: 30px;">
                <h3><?= Loc::getMessage('CONTACT_INFO') ?></h3>
                <ul style="list-style-type: none; padding-left: 0;">
                    <li style="margin-bottom: 10px;">
                        <strong><?= Loc::getMessage('SUPPORT_EMAIL') ?>:</strong> 
                        <a href="mailto:info@lead-space.ru">info@lead-space.ru</a>
                    </li>
                    <li style="margin-bottom: 10px;">
                        <strong><?= Loc::getMessage('SUPPORT_PHONE') ?>:</strong> 
                        <a href="tel:+78001234567">+7 939 111-20-43</a>
                    </li>
                </ul>
            </div>
            
            <div class="adm-detail-content-item-block" style="margin-top: 30px;">
                <h3><?= Loc::getMessage('WORKING_HOURS') ?></h3>
                <p><?= Loc::getMessage('WORKING_HOURS_TEXT') ?></p>
            </div>
        </div>
    </div>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';