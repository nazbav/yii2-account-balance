# Справочник: параметры и контракты

## Базовый интерфейс

`nazbav\balance\ManagerInterface`:

- `increase(mixed $account, int|float $amount, array $data = [])`
- `decrease(mixed $account, int|float $amount, array $data = [])`
- `transfer(mixed $from, mixed $to, int|float $amount, array $data = [])`
- `revert(mixed $transactionId, array $data = [])`
- `calculateBalance(mixed $account): int|float|null`

## Общие параметры Manager

- `autoCreateAccount: bool`
  - Автоматически создаёт счёт при передаче фильтра и отсутствии записи.
- `amountAttribute: string`
  - Имя поля суммы в транзакции.
- `accountLinkAttribute: string`
  - Имя поля связи транзакции со счётом.
- `extraAccountLinkAttribute: ?string`
  - Поле второго счёта для переводов.
- `accountBalanceAttribute: ?string`
  - Поле текущего баланса в таблице счёта.
- `dateAttribute: string`
  - Имя поля даты транзакции.
- `dateAttributeValue: mixed`
  - Значение или callback даты.

### Защитные параметры

- `requirePositiveAmount: bool`
  - Внешние операции принимают только суммы `> 0`.
- `forbidTransferToSameAccount: bool`
  - Запрещает переводы на тот же счёт.
- `forbidNegativeBalance: bool`
  - Блокирует списание ниже порога.
- `minimumAllowedBalance: int|float`
  - Порог минимального баланса.

### ООП-настройка через объект правил

Для удобной и явной настройки можно использовать `BalanceRules`:

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

Публичные методы управления правилами:

- `setBalanceRules(BalanceRules $rules): void`
- `getBalanceRules(): BalanceRules`
- `enableStrictMode(int|float $minimumAllowedBalance = 0): void`

## ManagerDb

Дополнительные параметры:

- `db: Connection|array|string`
- `accountTable: string`
- `transactionTable: string`

Особенности:

- при `forbidNegativeBalance=true` использует атомарное обновление баланса с условием;
- валидирует имена колонок в динамических местах;
- исключает auto-increment PK из явной вставки транзакции.

## ManagerActiveRecord

Параметры:

- `accountClass: string`
- `transactionClass: string`

Особенности:

- фильтрует writable-атрибуты;
- исключает auto-increment PK из прямого массового присвоения;
- при `forbidNegativeBalance=true` делает условное атомарное обновление.

## Сериализация данных

Через `ManagerDataSerializeTrait`:

- `dataAttribute: ?string`
- `serializer: string|array|SerializerInterface`

Поддерживаемые короткие сериализаторы:

- `json`
- `php`

## События

- `Manager::EVENT_BEFORE_CREATE_TRANSACTION`
- `Manager::EVENT_AFTER_CREATE_TRANSACTION`

```php
$manager->on(Manager::EVENT_BEFORE_CREATE_TRANSACTION, static function ($event) {
    $event->transactionData['traceId'] = Yii::$app->request->headers->get('X-Trace-Id');
});
```

## i18n

- Категория: `nazbav.balance`
- Базовый язык исходных сообщений: `ru-RU`
- Файлы:
  - `messages/ru/nazbav.balance.php`
  - `messages/en/nazbav.balance.php`

## Типовые исключения

- `yii\base\InvalidArgumentException`
  - неверная сумма, перевод на тот же счёт, отсутствие транзакции и т.д.
- `yii\base\InvalidConfigException`
  - неверные классы/таблицы/колонки и ошибки конфигурации защиты.
