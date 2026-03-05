# Архитектура и потоки данных

Документ описывает фактическую архитектуру библиотеки и интеграционные контуры.

## 1. Архитектурные цели

- атомарность операций баланса;
- предсказуемая модель ошибок;
- независимость от прикладной доменной логики;
- расширяемость через события и дополнительные атрибуты транзакции.

## 2. Слои

```mermaid
flowchart LR
    A[Внешний API/CRM/Billing] --> B[Доменный сервис]
    B --> C[Balance Manager]
    C --> D[ManagerDb]
    C --> E[ManagerActiveRecord]
    D --> F[(MySQL)]
    E --> G[(AR Storage)]
    C --> H[i18n сообщения]
```

## 3. Диаграмма классов

```mermaid
classDiagram
    class ManagerInterface
    class Manager {
      +increase()
      +decrease()
      +transfer()
      +revert()
      +calculateBalance()
      +setBalanceRules()
      +enableStrictMode()
    }
    class ManagerDbTransaction
    class ManagerDb
    class ManagerActiveRecord
    class ManagerDataSerializeTrait
    class BalanceRules
    class TransactionEvent

    ManagerInterface <|.. Manager
    Manager <|-- ManagerDbTransaction
    ManagerDbTransaction <|-- ManagerDb
    ManagerDbTransaction <|-- ManagerActiveRecord
    Manager ..> BalanceRules
    Manager ..> TransactionEvent
    ManagerDb ..> ManagerDataSerializeTrait
    ManagerActiveRecord ..> ManagerDataSerializeTrait
```

## 4. Поток операции `transfer`

```mermaid
sequenceDiagram
    participant Client as Клиент
    participant Domain as Доменный сервис
    participant Manager as Manager
    participant DB as База данных

    Client->>Domain: transfer(from, to, amount, data)
    Domain->>Domain: Валидация идемпотентности и антифрода
    Domain->>Manager: transfer(...)
    Manager->>Manager: Проверка правил и счетов
    Manager->>DB: BEGIN
    Manager->>DB: UPDATE balance(from)
    Manager->>DB: INSERT tx(from)
    Manager->>DB: UPDATE balance(to)
    Manager->>DB: INSERT tx(to)
    DB-->>Manager: COMMIT
    Manager-->>Domain: [txFromId, txToId]
```

## 5. Состояния доменной операции

```mermaid
stateDiagram-v2
    [*] --> Accepted
    Accepted --> Validated: Проверен формат и обязательные поля
    Validated --> Rejected: Нарушены правила
    Validated --> RiskCheck: Проверка антифрода
    RiskCheck --> Pending: Нужна задержка/ручная проверка
    RiskCheck --> Executed: Риск низкий
    Pending --> Executed: Проверка пройдена
    Pending --> Reverted: Подтверждено мошенничество
    Executed --> Reverted: Откат
    Rejected --> [*]
    Reverted --> [*]
    Executed --> [*]
```

## 6. Инварианты библиотеки

- сумма приводится к числу и проверяется на конечность;
- в публичных методах применяется контроль положительной суммы (если включен);
- перевод на тот же счет запрещается (если включено);
- для `increase/decrease/transfer/revert` обеспечивается транзакционность в `ManagerDbTransaction`;
- при защите от отрицательного баланса списание атомарно отклоняется при недостатке средств;
- произвольные данные транзакции восстанавливаются только как массив.

## 7. Ограничения ответственности

Библиотека не реализует самостоятельно:

- ключ идемпотентности на уровне внешнего API;
- скоринг устройства/IP/поведенческих аномалий;
- бизнес-лимиты по клиенту и периоду;
- жизненный цикл claim/dispute/refund;
- аудит и алертинг уровня компании.

Эти задачи находятся в доменном слое приложения.

## 8. Рекомендованный deployment-контур

```mermaid
flowchart TB
    A[App Pod 1] --> D[(MySQL Primary)]
    B[App Pod 2] --> D
    C[App Pod N] --> D
    D --> E[(Read Replica)]
    A --> F[Idempotency Store]
    B --> F
    C --> F
    A --> G[Fraud Engine]
    B --> G
    C --> G
    G --> H[Manual Review Queue]
```
