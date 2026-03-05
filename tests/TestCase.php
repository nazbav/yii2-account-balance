<?php

declare(strict_types=1);

namespace nazbav\tests\unit\balance;

use Yii;
use yii\helpers\ArrayHelper;

/**
 * Базовый класс для тестов.
 */
class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var array<string, mixed>|null
     */
    public static $params;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->destroyApplication();
    }

    /**
     * Возвращает параметр тестовой конфигурации из файла /data/config.php.
     * @param  string $name имя параметра.
     * @param  mixed $default значение по умолчанию, если параметр не задан.
     * @return mixed значение параметра конфигурации.
     */
    public static function getParam($name, mixed $default = null)
    {
        if (static::$params === null) {
            static::$params = require(__DIR__ . '/data/config.php');
        }

        return static::$params[$name] ?? $default;
    }

    /**
     * Заполняет Yii::$app новым экземпляром приложения.
     * Приложение автоматически уничтожается в tearDown().
     *
     * @param array<string, mixed> $config конфигурация приложения при необходимости.
     * @param class-string $appClass имя класса создаваемого приложения.
     */
    protected function mockApplication(array $config = [], string $appClass = '\yii\console\Application'): void
    {
        $dbConfig = static::getParam('db', [
            'class' => 'yii\db\Connection',
            'dsn' => 'sqlite::memory:',
        ]);
        if (!isset($dbConfig['class'])) {
            $dbConfig['class'] = 'yii\db\Connection';
        }

        new $appClass(ArrayHelper::merge([
            'id' => 'testapp',
            'basePath' => __DIR__,
            'vendorPath' => $this->getVendorPath(),
            'components' => [
                'db' => $dbConfig,
            ],
        ], $config));
    }

    /**
     * @return string путь к каталогу vendor.
     */
    protected function getVendorPath(): string
    {
        return dirname(__DIR__) . '/vendor';
    }

    /**
     * Удаляет приложение из Yii::$app, устанавливая значение `null`.
     */
    protected function destroyApplication(): void
    {
        /** @phpstan-ignore-next-line Сбрасываем Yii::$app между тестами. */
        Yii::$app = null;
    }
}
