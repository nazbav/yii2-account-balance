Журнал изменений nazbav/yii2-account-balance
=============================================

1.1.0, March 5, 2026
--------------------

- Я полностью перенёс пакет на `nazbav/yii2-account-balance`.
- Я сменил namespace с `yii2tech\balance` на `nazbav\balance`.
- Я обновил `composer.json`, bootstrap, i18n-категорию и имена файлов переводов.
- Я заменил все пути, ссылки и алиасы в коде, тестах и документации на `nazbav`.
- Я проверил совместимость: `phpunit`, `phpstan` и `psalm --taint-analysis` проходят без ошибок.

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
