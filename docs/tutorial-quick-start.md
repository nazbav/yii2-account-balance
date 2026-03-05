# Руководство: быстрый старт и базовая интеграция

## 1. Подключение

```bash
composer require nazbav/yii2-account-balance --prefer-dist
```

## 2. Конфигурация компонента

```php
use nazbav\balance\ManagerDb;

return [
    'components' => [
        'balanceManager' => [
            'class' => ManagerDb::class,
            'accountTable' => '{{%balance_account}}',
            'transactionTable' => '{{%balance_transaction}}',
            'accountLinkAttribute' => 'accountId',
            'extraAccountLinkAttribute' => 'extraAccountId',
            'amountAttribute' => 'amount',
            'dateAttribute' => 'createdAt',
            'dataAttribute' => 'data',
            'accountBalanceAttribute' => 'balance',
            'autoCreateAccount' => true,

            // Базовая защита бизнес-операций.
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
  walletType VARCHAR(32) NOT NULL,
  balance DECIMAL(19,4) NOT NULL DEFAULT 0,
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_wallet (userId, walletType)
) ENGINE=InnoDB;

CREATE TABLE balance_transaction (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  createdAt DATETIME NOT NULL,
  accountId BIGINT UNSIGNED NOT NULL,
  extraAccountId BIGINT UNSIGNED NULL,
  amount DECIMAL(19,4) NOT NULL,
  data JSON NULL,
  PRIMARY KEY (id),
  KEY idx_account_date (accountId, createdAt),
  KEY idx_extra_account (extraAccountId),
  CONSTRAINT fk_transaction_account FOREIGN KEY (accountId) REFERENCES balance_account(id)
) ENGINE=InnoDB;
```

## 4. Первые операции

```php
$manager = Yii::$app->balanceManager;

// Начисление.
$incomeId = $manager->increase(['userId' => 1001, 'walletType' => 'bonus'], 300, [
    'operationType' => 'purchase_reward',
    'orderId' => 'ORD-100500',
]);

// Списание.
$expenseId = $manager->decrease(['userId' => 1001, 'walletType' => 'bonus'], 120, [
    'operationType' => 'redeem',
    'redeemId' => 'RDM-7788',
]);

// Откат ошибочной операции.
$manager->revert($expenseId, [
    'operationType' => 'manual_fix',
    'reason' => 'Исправление по тикету support',
]);
```

## 5. Что сделать сразу в промышленной среде

1. Включить уникальный `operationId` в `$data` для идемпотентности на уровне приложения.
2. Добавить бизнес-лимиты на период (день/неделя/месяц) по сумме начислений и списаний.
3. Логировать риск-флаги (`riskScore`, `ipHash`, `deviceHash`, `geo`).
4. Для реферальных программ и крупных операций использовать отложенное подтверждение.
