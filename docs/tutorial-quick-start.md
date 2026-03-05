# Быстрый старт

Документ для первого внедрения в проект на Yii2 с MySQL.

## 1. Установка

```bash
composer require nazbav/yii2-account-balance --prefer-dist
```

## 2. Подключение компонента `ManagerDb`

```php
use nazbav\balance\ManagerDb;

return [
    'components' => [
        'balanceManager' => [
            'class' => ManagerDb::class,
            'db' => 'db',
            'accountTable' => '{{%balance_account}}',
            'transactionTable' => '{{%balance_transaction}}',
            'accountLinkAttribute' => 'accountId',
            'extraAccountLinkAttribute' => 'extraAccountId',
            'accountBalanceAttribute' => 'balance',
            'amountAttribute' => 'amount',
            'dateAttribute' => 'createdAt',
            'dataAttribute' => 'data',
            'autoCreateAccount' => true,

            // Строгий профиль для продакшна.
            'requirePositiveAmount' => true,
            'forbidTransferToSameAccount' => true,
            'forbidNegativeBalance' => true,
            'minimumAllowedBalance' => 0,
        ],
    ],
];
```

## 3. Минимальная схема MySQL

```sql
CREATE TABLE balance_account (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  userId BIGINT UNSIGNED NOT NULL,
  walletType VARCHAR(64) NOT NULL,
  balance DECIMAL(19,4) NOT NULL DEFAULT 0,
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_balance_account_user_wallet (userId, walletType)
) ENGINE=InnoDB;

CREATE TABLE balance_transaction (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  createdAt DATETIME NOT NULL,
  accountId BIGINT UNSIGNED NOT NULL,
  extraAccountId BIGINT UNSIGNED NULL,
  amount DECIMAL(19,4) NOT NULL,
  data JSON NULL,
  PRIMARY KEY (id),
  KEY idx_balance_transaction_account_date (accountId, createdAt),
  KEY idx_balance_transaction_extra_account (extraAccountId),
  CONSTRAINT fk_balance_transaction_account
    FOREIGN KEY (accountId) REFERENCES balance_account(id)
) ENGINE=InnoDB;
```

## 4. Первые операции

```php
$manager = Yii::$app->balanceManager;

$incomeId = $manager->increase(
    ['userId' => 1001, 'walletType' => 'bonus_available'],
    500,
    [
        'operationId' => 'purchase:100500:bonus',
        'operationType' => 'purchase_bonus',
        'orderId' => 100500,
    ]
);

$transferIds = $manager->transfer(
    ['userId' => 1001, 'walletType' => 'bonus_available'],
    ['userId' => 1001, 'walletType' => 'bonus_spent'],
    120,
    [
        'operationId' => 'redeem:100500',
        'operationType' => 'redeem',
    ]
);

$manager->revert($transferIds[0], [
    'operationType' => 'manual_correction',
    'reason' => 'Отмена списания оператором',
]);
```

## 5. Подключение через `ManagerActiveRecord`

```php
use nazbav\balance\ManagerActiveRecord;

return [
    'components' => [
        'balanceManager' => [
            'class' => ManagerActiveRecord::class,
            'accountClass' => app\models\BalanceAccount::class,
            'transactionClass' => app\models\BalanceTransaction::class,
            'accountBalanceAttribute' => 'balance',
            'requirePositiveAmount' => true,
            'forbidTransferToSameAccount' => true,
            'forbidNegativeBalance' => true,
            'minimumAllowedBalance' => 0,
        ],
    ],
];
```

## 6. Рекомендуемые защитные настройки использования

1. Вводить уникальный `operationId` для каждой внешней операции.
2. Хранить таблицу идемпотентности с уникальным индексом по `operationId`.
3. Выполнять доменную антифрод-проверку до вызова `increase/decrease/transfer`.
4. Разделять кошельки по назначению (`pending`, `available`, `spent`, `qualifying`).
