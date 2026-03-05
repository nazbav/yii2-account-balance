<?php

declare(strict_types=1);


// Включаем отчёт по всем возможным ошибкам PHP.
error_reporting(-1);

define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);
$_SERVER['SCRIPT_NAME'] = '/' . __DIR__;
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../vendor/yiisoft/yii2/Yii.php');
require_once(__DIR__ . '/autoload-nazbav.php');

Yii::setAlias('@nazbav/tests/unit/balance', __DIR__);
Yii::setAlias('@nazbav/yii2-account-balance', dirname(__DIR__) . '/src');
