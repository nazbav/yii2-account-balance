<?php

declare(strict_types=1);

namespace yii2tech\balance;

use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\i18n\PhpMessageSource;

/**
 * Bootstrap подключает сообщения расширения в i18n.
 */
class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        if (!($app instanceof Application) || !isset($app->i18n)) {
            return;
        }

        if (isset($app->i18n->translations[Manager::I18N_CATEGORY])) {
            return;
        }

        $app->i18n->translations[Manager::I18N_CATEGORY] = [
            'class' => PhpMessageSource::class,
            'basePath' => dirname(__DIR__) . '/messages',
            // Искусственный sourceLanguage, чтобы сообщения всегда брались из словаря.
            'sourceLanguage' => 'xx-XX',
            'fileMap' => [
                Manager::I18N_CATEGORY => 'yii2tech.balance.php',
            ],
        ];
    }
}
