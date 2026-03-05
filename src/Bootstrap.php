<?php

declare(strict_types=1);

namespace nazbav\balance;

use yii\base\BootstrapInterface;
use yii\i18n\PhpMessageSource;

/**
 * Bootstrap подключает сообщения расширения в i18n.
 */
class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        if (!isset($app->i18n)) {
            return;
        }

        if (isset($app->i18n->translations[Manager::I18N_CATEGORY])) {
            return;
        }

        $app->i18n->translations[Manager::I18N_CATEGORY] = [
            'class' => PhpMessageSource::class,
            'basePath' => dirname(__DIR__) . '/messages',
            // Базовый язык исходных сообщений расширения.
            'sourceLanguage' => 'ru-RU',
            'fileMap' => [
                Manager::I18N_CATEGORY => 'nazbav.balance.php',
            ],
        ];
    }
}
