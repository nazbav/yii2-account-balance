# Фактическая матрица поведения

Документ фиксирует точное поведение по текущему коду, без допущений.

## 1. Семантика транзакций по методам

| Метод | Отдельная DB-транзакция в `ManagerDbTransaction` | Комментарий |
|---|---|---|
| `increase()` | Да | Метод переопределен в `ManagerDbTransaction` и оборачивается в `begin/commit/rollback`. |
| `decrease()` | Да | Метод обернут в `begin/commit/rollback` в `ManagerDbTransaction`. |
| `transfer()` | Да | Внешняя транзакция + внутренние вызовы `decrease()/increase()` выполняются в одном контуре. |
| `revert()` | Да | Внешняя транзакция оборачивает подбор и выполнение обратной операции. |
| `calculateBalance()` | Нет | Чтение агрегата `SUM` без транзакционного контура. |

## 2. Фактическая логика `revert()`

| Исходная транзакция | Что делает `revert()` |
|---|---|
| Обычная транзакция с положительной суммой | Вызывает `decrease()` на тот же счет. |
| Обычная транзакция с отрицательной суммой | Вызывает `increase()` на тот же счет. |
| Транзакция перевода с `extraAccountLinkAttribute` и отрицательной суммой | Выполняет обратный `transfer(extra -> account)`. |
| Транзакция перевода с `extraAccountLinkAttribute` и положительной суммой | Выполняет обратный `transfer(account -> extra)`. |

## 3. Суммы и валидация

| Проверка | Фактическое поведение |
|---|---|
| Тип суммы | Принимаются `int`, `float`, numeric-string. |
| Бесконечность | `INF/-INF` отклоняются (`error.amount_must_be_finite`). |
| `NaN` | Отклоняется как нечисловое (`error.amount_not_numeric`). |
| Положительность | При `requirePositiveAmount=true` сумма должна быть строго `> 0`. |

## 4. Работа со счетом

| Вход `account` | Поведение |
|---|---|
| Скалярный ID | Используется напрямую как ID счета. |
| Массив-фильтр | Выполняется поиск `findAccountId()`. |
| Фильтр не найден и `autoCreateAccount=true` | Создается новый счет через `createAccount()`. |
| Фильтр не найден и `autoCreateAccount=false` | Исключение `error.account_not_found_by_filter`. |
| Фильтр содержит только неизвестные атрибуты схемы | Исключение `error.account_attributes_empty_after_filter`. |
| Конкурентное автосоздание того же счета (duplicate key) | Возвращается ID уже созданного счета, если запись доступна для повторного поиска. |

## 5. Защита от перерасхода

| Параметр | Поведение |
|---|---|
| `forbidNegativeBalance=false` | Баланс может уходить ниже нуля. |
| `forbidNegativeBalance=true` + `accountBalanceAttribute!==null` | Выполняется условное атомарное обновление, при неуспехе `error.insufficient_funds`. |
| `forbidNegativeBalance=true` + `accountBalanceAttribute===null` | Конфигурационная ошибка `error.account_balance_attribute_required_for_negative_protection`. |

## 6. Сериализация `data`

| Условие | Поведение |
|---|---|
| `dataAttribute=null` | Дополнительные поля не сериализуются. |
| `dataAttribute` задан | Неизвестные полям таблицы атрибуты упаковываются сериализатором. |
| `serializer='json'` | Используется `JsonSerializer`. |
| `serializer='php'` | Используется `PhpSerializer`. |
| `PhpSerializer` по умолчанию | `allowedClasses=false`, создание объектов в `unserialize()` запрещено. |

## 7. i18n и сообщения

- Категория: `nazbav.balance`.
- `sourceLanguage`: `ru-RU`.
- Файлы сообщений:
  - `messages/ru/nazbav.balance.php`
  - `messages/en/nazbav.balance.php`

Ключи ошибок (фактический перечень):

1. `error.transaction_not_found`
2. `error.account_not_found_by_filter`
3. `error.amount_not_numeric`
4. `error.amount_must_be_positive`
5. `error.amount_must_be_finite`
6. `error.transfer_same_account_forbidden`
7. `error.insufficient_funds`
8. `error.account_balance_attribute_required_for_negative_protection`
9. `error.invalid_column_name`
10. `error.serialized_data_must_be_array`
11. `error.account_primary_key_not_received`
12. `error.transaction_primary_key_not_received`
13. `error.table_not_found`
14. `error.table_pk_required`
15. `error.account_class_pk_required`
16. `error.property_must_be_active_record_class`
17. `error.account_attributes_empty_after_filter`

## 8. Тестовое покрытие (факты)

- Всего тестовых методов: `46`.
- Исполняемых тестов в `phpunit`: `52`.
- Распределение:
  - `tests/ManagerTest.php`: `20`;
  - `tests/ManagerDbTest.php`: `11`;
  - `tests/ManagerActiveRecordTest.php`: `11`;
  - `tests/ManagerDataSerializeTraitTest.php`: `4`.
- Проверяются позитивные и негативные сценарии по валидации сумм, запрету одинаковых счетов, защите от перерасхода, сериализации и событиям.
