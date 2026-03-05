# Справочник конфигурации и API

## Контракт `ManagerInterface`

```php
interface ManagerInterface
{
    public function increase(mixed $account, int|float $amount, array $data = []): mixed;
    public function decrease(mixed $account, int|float $amount, array $data = []): mixed;
    public function transfer(mixed $from, mixed $to, int|float $amount, array $data = []): array;
    public function revert(mixed $transactionId, array $data = []): mixed;
    public function calculateBalance(mixed $account): int|float|null;
}
```

## Общие свойства `Manager`

| Свойство | Тип | По умолчанию | Назначение |
|---|---|---|---|
| `autoCreateAccount` | `bool` | `true` | Создавать счет автоматически, если передан фильтр и запись не найдена. |
| `amountAttribute` | `string` | `amount` | Имя поля суммы в транзакции. |
| `accountLinkAttribute` | `string` | `accountId` | Имя поля связи транзакции со счетом. |
| `extraAccountLinkAttribute` | `?string` | `null` | Поле второго счета в переводе. |
| `accountBalanceAttribute` | `?string` | `null` | Поле текущего баланса счета. |
| `dateAttribute` | `string` | `date` | Имя поля даты транзакции. |
| `dateAttributeValue` | `mixed` | `null` | Значение даты или callback. |
| `requirePositiveAmount` | `bool` | `true` | Требовать сумму `> 0` в публичных операциях. |
| `forbidTransferToSameAccount` | `bool` | `true` | Запрещать перевод между одинаковыми счетами. |
| `forbidNegativeBalance` | `bool` | `false` | Блокировать уход ниже минимума. |
| `minimumAllowedBalance` | `int|float` | `0` | Нижняя граница баланса. |

## Настройка правил через `BalanceRules`

```php
use nazbav\balance\BalanceRules;

$manager->setBalanceRules(new BalanceRules(
    requirePositiveAmount: true,
    forbidTransferToSameAccount: true,
    forbidNegativeBalance: true,
    minimumAllowedBalance: 0,
));
```

Быстрый строгий профиль:

```php
$manager->enableStrictMode();
// или
$manager->setBalanceRules(BalanceRules::strict());
```

## События

- `Manager::EVENT_BEFORE_CREATE_TRANSACTION`
- `Manager::EVENT_AFTER_CREATE_TRANSACTION`

```php
use nazbav\balance\Manager;
use nazbav\balance\TransactionEvent;

$manager->on(Manager::EVENT_BEFORE_CREATE_TRANSACTION, static function (TransactionEvent $event): void {
    $event->transactionData['traceId'] = Yii::$app->request->headers->get('X-Trace-Id');
});
```

## `ManagerDb`

Дополнительные свойства:

| Свойство | Тип | Назначение |
|---|---|---|
| `db` | `Connection|array|string` | Подключение к БД или ID компонента. |
| `accountTable` | `string` | Таблица счетов. |
| `transactionTable` | `string` | Таблица транзакций. |

Особенности поведения:

- определение PK таблиц выполняется автоматически;
- имена колонок валидируются regexp-паттерном;
- при `forbidNegativeBalance=true` применяется атомарное условное `UPDATE`;
- автоинкрементный PK исключается из пользовательских атрибутов вставки.

## `ManagerActiveRecord`

Дополнительные свойства:

| Свойство | Тип | Назначение |
|---|---|---|
| `accountClass` | `string` | Класс ActiveRecord для счетов. |
| `transactionClass` | `string` | Класс ActiveRecord для транзакций. |

Особенности поведения:

- проверка, что классы наследуются от `yii\db\ActiveRecord`;
- фильтрация writable-атрибутов;
- исключение auto-increment PK из массовой записи;
- условное атомарное обновление баланса в режиме защиты от перерасхода.

## Сериализация `data`

Трейт: `ManagerDataSerializeTrait`.

| Свойство | Тип | По умолчанию | Назначение |
|---|---|---|---|
| `dataAttribute` | `?string` | `data` | Поле для сериализованных произвольных данных. |
| `serializer` | `string|array|SerializerInterface` | `json` | Механизм сериализации. |

Поддерживаемые короткие имена сериализатора:

- `json` → `JsonSerializer`;
- `php` → `PhpSerializer`.

Безопасность `PhpSerializer`:

- по умолчанию `allowedClasses=false`;
- объектная инъекция через `unserialize()` блокируется.

## i18n

- Категория: `nazbav.balance`.
- Источник переводов: `messages/*/nazbav.balance.php`.
- Базовый язык исходных сообщений: `ru-RU` (`sourceLanguage`).

## Коды ошибок и исключения

| Ключ сообщения | Тип исключения | Когда возникает |
|---|---|---|
| `error.amount_not_numeric` | `InvalidArgumentException` | Сумма не является числом. |
| `error.amount_must_be_positive` | `InvalidArgumentException` | Сумма `<= 0` при строгом требовании. |
| `error.amount_must_be_finite` | `InvalidArgumentException` | Переданы `INF/-INF/NAN`. |
| `error.transfer_same_account_forbidden` | `InvalidArgumentException` | Перевод на тот же счет запрещен. |
| `error.transaction_not_found` | `InvalidArgumentException` | Не найдена транзакция для `revert()`. |
| `error.insufficient_funds` | `InvalidArgumentException` | Недостаточно средств в режиме защиты. |
| `error.invalid_column_name` | `InvalidConfigException` | Небезопасное имя колонки. |
| `error.account_attributes_empty_after_filter` | `InvalidArgumentException` | Для автосоздания счета передан фильтр без допустимых атрибутов схемы. |
| `error.table_not_found` | `InvalidConfigException` | Не найдена таблица. |
| `error.table_pk_required` | `InvalidConfigException` | У таблицы нет PK. |
| `error.property_must_be_active_record_class` | `InvalidConfigException` | Неверный AR-класс. |

## Матрица поведения операций

| Операция | Сумма <= 0 | Недостаточно средств | Счет не найден по фильтру | Результат | Отдельная DB-транзакция |
|---|---|---|---|---|---|
| `increase` | ошибка (если `requirePositiveAmount=true`) | не применимо | автосоздание / ошибка | одна транзакция с `+amount` | да |
| `decrease` | ошибка (если `requirePositiveAmount=true`) | ошибка (если `forbidNegativeBalance=true`) | автосоздание / ошибка | одна транзакция с `-amount` | да |
| `transfer` | ошибка (если `requirePositiveAmount=true`) | ошибка на счете-источнике | автосоздание / ошибка | две транзакции | да |
| `revert` | не применимо | зависит от исходной операции | не применимо | обратная операция | да |

Детализация фактической логики: [Фактическая матрица поведения](reference-behavior-matrix.md).

## Рекомендованный профиль для продакшна

```php
'autoCreateAccount' => false,
'requirePositiveAmount' => true,
'forbidTransferToSameAccount' => true,
'forbidNegativeBalance' => true,
'minimumAllowedBalance' => 0,
'accountBalanceAttribute' => 'balance',
```

Если `autoCreateAccount=false`, счет должен создаваться отдельным доменным процессом.
