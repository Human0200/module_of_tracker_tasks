<?php
use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);
?>
<div style="padding: 20px;">
    <h2><?= Loc::getMessage('PARSER_UNINSTALL_SUCCESS') ?></h2>
    <form action="<?= $APPLICATION->GetCurPage() ?>">
        <input type="hidden" name="lang" value="<?= LANG ?>">
        <input type="submit" value="<?= Loc::getMessage('MOD_BACK') ?>">
    </form>
</div>