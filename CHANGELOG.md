Журнал изменений nazbav/yii2-account-balance
=============================================

1.4.0, March 5, 2026
--------------------

- Усилен статический анализ:
  - подключены `phpstan/phpstan-strict-rules` и `phpstan/phpstan-deprecation-rules`;
  - удалены локальные `ignoreErrors` для пропуска missing-type ошибок;
  - приведены тесты и вспомогательный код к новым строгим требованиям.
- Расширен CI-контур:
  - в `.github/workflows/php.yml` добавлены `dependency-review` и `actionlint`;
  - добавлен отдельный security workflow `.github/workflows/security.yml` с `gitleaks` и `CodeQL` для GitHub Actions.
- Повышена типобезопасность тестовой инфраструктуры:
  - добавлены return types и точные generic-phpdoc в тестовых классах;
  - устранены нестрогие конструкции (`empty`, short ternary и неоднозначные bool-проверки).

1.3.0, March 5, 2026
--------------------

- Добавлены quality-gates и автоисправления кода:
  - `friendsofphp/php-cs-fixer`;
  - `rector/rector`;
  - `php-parallel-lint/php-parallel-lint`.
- Добавлены конфигурационные файлы `.php-cs-fixer.dist.php` и `rector.php`.
- Расширены Composer-скрипты:
  - `lint:syntax`, `cs:check`, `cs:fix`, `rector:check`, `rector:fix`;
  - `qa` включает новые этапы до тестов и статанализа.
- Обновлён workflow `.github/workflows/php.yml`:
  - добавлены шаги `parallel-lint`, `php-cs-fixer` (dry-run), `rector` (dry-run).
- Применён автоматический рефакторинг и выравнивание стиля в `src/` и `tests/`.
- Добавлен `.php-cs-fixer.cache` в `.gitignore`.

1.2.0, March 5, 2026
--------------------

- Добавлен объект `BalanceRules` для явной ООП-настройки правил операций.
- Добавлены методы `setBalanceRules()`, `getBalanceRules()` и `enableStrictMode()` в `Manager`.
- Усилена валидация суммы операций: отклоняются нечисловые и бесконечные значения.
- Усилены защитные проверки в `ManagerDb` и `ManagerActiveRecord`:
  - проверка безопасных имён колонок;
  - атомарная защита от перерасхода баланса;
  - защита от прямой вставки auto-increment PK.
- Обновлён CI workflow:
  - матрица PHP `8.1` и `8.3`;
  - MySQL `8.0` для тестов;
  - повторные попытки установки зависимостей при сетевых сбоях.
- Актуализирована документация: структура, терминология, кейсы лояльности и реферальной программы.
- Уточнена регистрация i18n: `sourceLanguage` установлен в `ru-RU`.

1.1.0, March 5, 2026
--------------------

- Пакет полностью перенесён на `nazbav/yii2-account-balance`.
- Namespace изменён с `yii2tech\balance` на `nazbav\balance`.
- Обновлены `composer.json`, bootstrap, i18n-категория и имена файлов переводов.
- Обновлены пути, ссылки и алиасы в коде, тестах и документации на `nazbav`.
- Проверена совместимость: `phpunit`, `phpstan` и `psalm --taint-analysis` проходят без ошибок.

1.0.3, September 19, 2018
-------------------------

- Enh: Usage of deprecated `yii\base\InvalidParamException` changed to `yii\base\InvalidArgumentException` one (klimov-paul)


1.0.2, November 3, 2017
-----------------------

- Bug #11: Fixed `ManagerDb` considers autoincrement primary key being allowed for direct transaction data saving (klimov-paul)
- Bug: Usage of deprecated `yii\base\Object` changed to `yii\base\BaseObject` allowing compatibility with PHP 7.2 (klimov-paul)


1.0.1, July 27, 2016
--------------------

- Bug #4: Fixed `ManagerDbTransaction::transfer()` does not commit internal transaction (klimov-paul)


1.0.0, May 2, 2016
------------------

- Initial release.
