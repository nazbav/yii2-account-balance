# Архитектура и потоки данных

Документ описывает фактическую архитектуру библиотеки и её внутренние контуры выполнения операций.

## 1. Архитектурные цели

- атомарность операций баланса;
- предсказуемая модель ошибок;
- независимость от прикладной доменной логики;
- расширяемость через события и дополнительные атрибуты транзакции.

## 2. Слои библиотеки

```mermaid
flowchart LR
    A[Клиентский код приложения] --> C[Balance Manager]
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

## 4. Поток операции `transfer` внутри библиотеки

```mermaid
sequenceDiagram
    participant Client as Код приложения
    participant Manager as Manager
    participant DB as База данных

    Client->>Manager: transfer(...)
    Manager->>Manager: Проверка правил и счетов
    Manager->>DB: BEGIN
    Manager->>DB: UPDATE balance(from)
    Manager->>DB: INSERT tx(from)
    Manager->>DB: UPDATE balance(to)
    Manager->>DB: INSERT tx(to)
    DB-->>Manager: COMMIT
    Manager-->>Client: [txFromId, txToId]
```

## 5. Инварианты библиотеки

- сумма приводится к числу и проверяется на конечность;
- в публичных методах применяется контроль положительной суммы (если включен);
- перевод на тот же счет запрещается (если включено);
- для `increase/decrease/transfer/revert` обеспечивается транзакционность в `ManagerDbTransaction`;
- при защите от отрицательного баланса списание атомарно отклоняется при недостатке средств;
- произвольные данные транзакции восстанавливаются только как массив.

## 6. Точки расширения библиотеки

- события:
  - `Manager::EVENT_BEFORE_CREATE_TRANSACTION`;
  - `Manager::EVENT_AFTER_CREATE_TRANSACTION`;
- выбор backend-реализации:
  - `ManagerDb`;
  - `ManagerActiveRecord`;
- настройка правил через `BalanceRules` и `enableStrictMode()`.

## 7. Контур качества

```mermaid
flowchart LR
    A[phpunit] --> D[Quality Gate]
    B[phpstan level 8] --> D
    C[infection MSI 100] --> D
    D --> E[GitHub Actions]
```
