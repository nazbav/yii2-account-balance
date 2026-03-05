<?php

declare(strict_types=1);

namespace nazbav\tests\unit\balance;

use nazbav\balance\ManagerDb;
use Yii;
use yii\db\Query;

/**
 * @group db
 */
class ManagerDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestDbData();
    }

    /**
     * Создаёт таблицы для тестов.
     */
    protected function setupTestDbData(): void
    {
        $db = Yii::$app->getDb();

        // Структура таблиц.

        $table = 'BalanceAccount';
        try {
            $db->createCommand()->dropTable($table)->execute();
        } catch (\Throwable) {
            // Таблица может отсутствовать в новой базе.
        }

        $columns = [
            'id' => 'pk',
            'userId' => 'integer',
            'balance' => 'integer DEFAULT 0',
        ];
        $db->createCommand()->createTable($table, $columns)->execute();
        $db->createCommand()->createIndex('uq_balance_account_user_id', $table, ['userId'], true)->execute();

        $table = 'BalanceTransaction';
        try {
            $db->createCommand()->dropTable($table)->execute();
        } catch (\Throwable) {
            // Таблица может отсутствовать в новой базе.
        }

        $columns = [
            'id' => 'pk',
            'date' => 'integer',
            'accountId' => 'integer',
            'operationId' => 'string',
            'amount' => 'integer',
            'data' => 'text',
        ];
        $db->createCommand()->createTable($table, $columns)->execute();
    }

    /**
     * @return array<string, mixed> данные последней сохранённой транзакции.
     */
    protected function getLastTransaction(): array
    {
        $transaction = (new Query())
            ->from('BalanceTransaction')
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->one();
        self::assertIsArray($transaction);
        return $transaction;
    }

    // Набор тестов.

    public function testIncrease(): void
    {
        $manager = new ManagerDb();

        $manager->increase(1, 50);

        $transaction = $this->getLastTransaction();
        self::assertEquals(50, $transaction['amount']);

        $manager->increase(1, 50, ['extra' => 'custom']);
        $transaction = $this->getLastTransaction();
        self::assertStringContainsString('custom', $transaction['data']);
    }

    /**
     * @depends testIncrease
     */
    public function testAutoCreateAccount(): void
    {
        $manager = new ManagerDb();

        $manager->autoCreateAccount = true;
        $manager->increase(['userId' => 5], 10);
        $accounts = (new Query())->from('BalanceAccount')->all();
        self::assertCount(1, $accounts);
        self::assertEquals(5, $accounts[0]['userId']);

        $manager->autoCreateAccount = false;
        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(['userId' => 10], 10);
    }

    /**
     * @depends testAutoCreateAccount
     */
    public function testIncreaseAccountBalance(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';

        $amount = 50;
        $manager->increase(['userId' => 1], $amount);
        $account = (new Query())->from('BalanceAccount')->andWhere(['userId' => 1])->one();
        self::assertIsArray($account);

        self::assertEquals($amount, $account['balance']);

        // Обновление баланса существующего счёта.
        $amount = 50;
        $manager->increase(['userId' => 1], $amount);
        $account = (new Query())->from('BalanceAccount')->andWhere(['userId' => 1])->one();
        self::assertIsArray($account);
        self::assertEquals(100, $account['balance']);
    }

    /**
     * @depends testIncrease
     */
    public function testRevert(): void
    {
        $manager = new ManagerDb();

        $accountId = 1;
        $amount = 10;
        $transactionId = $manager->increase($accountId, $amount);
        $manager->revert($transactionId);

        $transaction = $this->getLastTransaction();
        self::assertEquals($accountId, $transaction['accountId']);
        self::assertEquals(-$amount, $transaction['amount']);
    }

    public function testTransferRejectsSameAccount(): void
    {
        $manager = new ManagerDb();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->transfer(1, 1, 10);
    }

    public function testGetIdAttributesAccessorsArePublic(): void
    {
        $manager = new ManagerDb();

        self::assertSame('id', $manager->getAccountIdAttribute());
        self::assertSame('id', $manager->getTransactionIdAttribute());
    }

    public function testForbidNegativeBalance(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidNegativeBalance = true;
        $manager->minimumAllowedBalance = 0;

        $manager->increase(['userId' => 100], 30);

        try {
            $manager->decrease(['userId' => 100], 50);
            self::fail('Ожидалось исключение о недостатке средств.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('Недостаточно средств', $invalidArgumentException->getMessage());
        }

        $account = (new Query())->from('BalanceAccount')->andWhere(['userId' => 100])->one();
        self::assertIsArray($account);
        self::assertEquals(30, $account['balance']);
    }

    public function testForbidNegativeBalanceAllowsDecreaseWhenFundsAreEnough(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidNegativeBalance = true;
        $manager->minimumAllowedBalance = 0;

        $manager->increase(['userId' => 101], 30);
        $manager->decrease(['userId' => 101], 10);

        $account = (new Query())->from('BalanceAccount')->andWhere(['userId' => 101])->one();
        self::assertIsArray($account);
        self::assertSame(20, (int) $account['balance']);
    }

    public function testPositiveBalanceUpdateTouchesOnlyTargetAccount(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidNegativeBalance = true;

        $manager->increase(['userId' => 201], 10);
        $manager->increase(['userId' => 202], 10);
        $manager->increase(['userId' => 201], 5);

        $first = (new Query())->from('BalanceAccount')->andWhere(['userId' => 201])->one();
        $second = (new Query())->from('BalanceAccount')->andWhere(['userId' => 202])->one();
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame(15, (int) $first['balance']);
        self::assertSame(10, (int) $second['balance']);
    }

    public function testDecreaseRollsBackBalanceWhenTransactionInsertFails(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';

        $manager->increase(['userId' => 777], 50);

        $manager->transactionTable = '{{%MissingBalanceTransactionTable}}';
        try {
            $manager->decrease(['userId' => 777], 10);
            self::fail('Ожидалось исключение при отсутствии таблицы транзакций.');
        } catch (\yii\base\InvalidConfigException) {
            // Ожидаемая ветка.
        }

        $account = (new Query())->from('BalanceAccount')->andWhere(['userId' => 777])->one();
        self::assertIsArray($account);
        self::assertEquals(50, $account['balance']);
    }

    public function testAutoCreateAccountRejectsUnknownFilterAttributes(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(['unknownAttr' => 1], 10);
    }

    public function testDuplicateOperationIdForSameAccountIsRejected(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;

        $manager->increase(['userId' => 9001], 40, ['operationId' => 'bonus:welcome:9001']);

        try {
            $manager->increase(['userId' => 9001], 40, ['operationId' => 'bonus:welcome:9001']);
            self::fail('Ожидалось исключение для повторного operationId.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('уже выполнена', $invalidArgumentException->getMessage());
        }

        $account = (new Query())->from('BalanceAccount')->andWhere(['userId' => 9001])->one();
        self::assertIsArray($account);
        self::assertEquals(40, $account['balance']);

        $transactions = (new Query())
            ->from('BalanceTransaction')
            ->where(['operationId' => 'bonus:welcome:9001'])
            ->all();
        self::assertCount(1, $transactions);
        self::assertSame(40, (int) $transactions[0]['amount']);
    }

    public function testDuplicateOperationIdAllowedForDifferentAccounts(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;

        $manager->increase(['userId' => 9101], 10, ['operationId' => 'campaign:shared']);
        $manager->increase(['userId' => 9102], 10, ['operationId' => 'campaign:shared']);

        $rows = (new Query())->from('BalanceTransaction')->where(['operationId' => 'campaign:shared'])->all();
        self::assertCount(2, $rows);
        self::assertSame(10, (int) $rows[0]['amount']);
        self::assertSame(10, (int) $rows[1]['amount']);
    }

    public function testRequireOperationIdRejectsMissingValue(): void
    {
        $manager = new ManagerDb();
        $manager->requireOperationId = true;

        try {
            $manager->increase(1, 10);
            self::fail('Ожидалось исключение при отсутствии operationId.');
        } catch (\yii\base\InvalidArgumentException) {
            // Ожидаемая ветка.
        }

        self::assertCount(0, (new Query())->from('BalanceTransaction')->all());
    }

    public function testRevertMissingTransactionContainsIdInMessage(): void
    {
        $manager = new ManagerDb();

        try {
            $manager->revert(999999);
            self::fail('Ожидалось исключение при отсутствии транзакции.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('999999', $invalidArgumentException->getMessage());
        }
    }

    public function testRevertMissingTransactionStillFailsWhenTableHasRows(): void
    {
        $manager = new ManagerDb();
        $manager->increase(1, 10);

        try {
            $manager->revert(999999);
            self::fail('Ожидалось исключение при отсутствии транзакции.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('999999', $invalidArgumentException->getMessage());
        }
    }

    public function testOperationIdAttributeRejectsUnsafeColumnName(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;
        $manager->operationIdAttribute = 'operationId; DROP TABLE BalanceTransaction;--';

        try {
            $manager->increase(['userId' => 9201], 10, ['operationId; DROP TABLE BalanceTransaction;--' => 'bad']);
            self::fail('Ожидалось исключение на небезопасном имени колонки.');
        } catch (\yii\base\InvalidConfigException) {
            // Ожидаемая ветка.
        }

        self::assertCount(0, (new Query())->from('BalanceTransaction')->all());
    }

    public function testOperationIdSqlPayloadIsHandledAsLiteralValue(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;

        $manager->increase(['userId' => 9202], 10, ['operationId' => 'safe-operation']);
        $manager->increase(['userId' => 9202], 10, ['operationId' => "payload' OR 1=1 --"]);

        $rows = (new Query())
            ->from('BalanceTransaction')
            ->where(['accountId' => 1])
            ->all();
        self::assertCount(2, $rows);
        self::assertSame('safe-operation', $rows[0]['operationId']);
        self::assertSame("payload' OR 1=1 --", $rows[1]['operationId']);
    }

    public function testCalculateBalanceRejectsUnsafeAmountColumnName(): void
    {
        $manager = new ManagerDb();
        $manager->amountAttribute = 'amount) OR 1=1 --';

        $this->expectException('yii\base\InvalidConfigException');
        $manager->calculateBalance(1);
    }

    public function testCalculateBalanceRejectsMissingAmountColumnName(): void
    {
        $manager = new ManagerDb();
        $manager->amountAttribute = 'missingAmountColumn';

        try {
            $manager->calculateBalance(1);
            self::fail('Ожидалось исключение при отсутствии колонки суммы.');
        } catch (\yii\base\InvalidConfigException $invalidConfigException) {
            self::assertStringContainsString('missingAmountColumn', $invalidConfigException->getMessage());
        }
    }

    public function testCalculateBalanceRejectsMissingAccountLinkColumnName(): void
    {
        $manager = new ManagerDb();
        $manager->accountLinkAttribute = 'missingAccountLinkColumn';

        try {
            $manager->calculateBalance(1);
            self::fail('Ожидалось исключение при отсутствии колонки связи со счётом.');
        } catch (\yii\base\InvalidConfigException $invalidConfigException) {
            self::assertStringContainsString('missingAccountLinkColumn', $invalidConfigException->getMessage());
        }
    }

    public function testDuplicateOperationCheckRejectsUnsafeAccountLinkColumnName(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;
        $manager->accountLinkAttribute = 'accountId) OR 1=1 --';

        try {
            $manager->increase(['userId' => 9203], 10, ['operationId' => 'safe-op']);
            self::fail('Ожидалось исключение на небезопасном accountLinkAttribute.');
        } catch (\yii\base\InvalidConfigException) {
            // Ожидаемая ветка.
        }

        self::assertCount(0, (new Query())->from('BalanceTransaction')->all());
    }

    public function testDuplicateOperationCheckRejectsMissingOperationIdColumnName(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;
        $manager->operationIdAttribute = 'missingOperationIdColumn';

        try {
            $manager->increase(['userId' => 9204], 10, ['missingOperationIdColumn' => 'safe-op']);
            self::fail('Ожидалось исключение на отсутствующей колонке operationId.');
        } catch (\yii\base\InvalidConfigException $invalidConfigException) {
            self::assertStringContainsString('missingOperationIdColumn', $invalidConfigException->getMessage());
        }
    }

    public function testDuplicateOperationCheckRejectsMissingAccountLinkColumnName(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;
        $manager->accountLinkAttribute = 'missingAccountLinkColumn';

        try {
            $manager->increase(['userId' => 9205], 10, ['operationId' => 'safe-op']);
            self::fail('Ожидалось исключение на отсутствующей колонке accountLinkAttribute.');
        } catch (\yii\base\InvalidConfigException $invalidConfigException) {
            self::assertStringContainsString('missingAccountLinkColumn', $invalidConfigException->getMessage());
        }
    }

    public function testCreateAccountReturnsExistingIdOnDuplicateKeyRace(): void
    {
        $manager = new class () extends ManagerDb {
            /**
             * @param array<string, mixed> $attributes
             */
            public function createAccountPublic(array $attributes): mixed
            {
                return $this->createAccount($attributes);
            }
        };

        $firstId = $manager->createAccountPublic(['userId' => 901]);
        $secondId = $manager->createAccountPublic(['userId' => 901]);

        self::assertSame((string) $firstId, (string) $secondId);
    }

    public function testCreateAccountReturnsJoinedCompositePrimaryKeys(): void
    {
        $db = Yii::$app->getDb();
        try {
            $db->createCommand()->dropTable('CompositeBalanceAccount')->execute();
        } catch (\Throwable) {
            // Таблица может отсутствовать.
        }
        $db->createCommand()->createTable('CompositeBalanceAccount', [
            'tenantId' => 'integer NOT NULL',
            'userId' => 'integer NOT NULL',
            'balance' => 'integer DEFAULT 0',
            'PRIMARY KEY(tenantId, userId)',
        ])->execute();

        $manager = new class () extends ManagerDb {
            /**
             * @param array<string, mixed> $attributes
             */
            public function createAccountPublic(array $attributes): mixed
            {
                return $this->createAccount($attributes);
            }
        };
        $manager->accountTable = 'CompositeBalanceAccount';
        $manager->transactionTable = 'BalanceTransaction';

        $id = $manager->createAccountPublic(['tenantId' => 7, 'userId' => 17]);

        self::assertSame('7,17', $id);
    }

    public function testFindAccountIdReadsPrimaryKeyColumnInsteadOfFirstSelectedColumn(): void
    {
        $db = Yii::$app->getDb();
        try {
            $db->createCommand()->dropTable('AltBalanceAccount')->execute();
        } catch (\Throwable) {
            // Таблица может отсутствовать.
        }
        $db->createCommand()->createTable('AltBalanceAccount', [
            'name' => 'string',
            'id' => 'pk',
            'balance' => 'integer DEFAULT 0',
        ])->execute();
        $db->createCommand()->insert('AltBalanceAccount', ['name' => 'acc-1', 'balance' => 0])->execute();

        $manager = new ManagerDb();
        $manager->accountTable = 'AltBalanceAccount';
        $manager->autoCreateAccount = false;

        $transactionId = $manager->increase(['name' => 'acc-1'], 10);
        $transaction = (new Query())->from('BalanceTransaction')->andWhere(['id' => $transactionId])->one();
        self::assertIsArray($transaction);
        self::assertSame(1, (int) $transaction['accountId']);
    }

    public function testCreateTransactionReturnsJoinedCompositePrimaryKeys(): void
    {
        $db = Yii::$app->getDb();
        try {
            $db->createCommand()->dropTable('CompositeBalanceTransaction')->execute();
        } catch (\Throwable) {
            // Таблица может отсутствовать.
        }
        $db->createCommand()->createTable('CompositeBalanceTransaction', [
            'accountId' => 'integer NOT NULL',
            'operationId' => 'string NOT NULL',
            'date' => 'integer',
            'amount' => 'integer',
            'data' => 'text',
            'PRIMARY KEY(accountId, operationId)',
        ])->execute();

        $manager = new class () extends ManagerDb {
            /**
             * @param array<string, mixed> $attributes
             */
            public function createTransactionPublic(array $attributes): mixed
            {
                return $this->createTransaction($attributes);
            }
        };
        $manager->accountTable = 'BalanceAccount';
        $manager->transactionTable = 'CompositeBalanceTransaction';

        $id = $manager->createTransactionPublic([
            'accountId' => 44,
            'operationId' => 'cmp-44',
            'date' => time(),
            'amount' => 100,
        ]);

        self::assertSame('44,cmp-44', $id);
    }

    public function testForbidNegativeBalanceDoesNotNeedMinimumForZeroAmount(): void
    {
        $manager = new ManagerDb();
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidNegativeBalance = true;
        $manager->minimumAllowedBalance = INF;
        $manager->requirePositiveAmount = false;

        $manager->decrease(1, 0);

        $transaction = $this->getLastTransaction();
        self::assertSame(1, (int) $transaction['accountId']);
        self::assertSame(0, (int) $transaction['amount']);
    }

    public function testInsufficientFundsMessageContainsAccountAndMinimum(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidNegativeBalance = true;
        $manager->minimumAllowedBalance = 5;

        $manager->increase(['userId' => 303], 5);

        try {
            $manager->decrease(['userId' => 303], 2);
            self::fail('Ожидалось исключение о недостатке средств.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('1', $invalidArgumentException->getMessage());
            self::assertStringContainsString('5', $invalidArgumentException->getMessage());
        }
    }

    /**
     * @depends testIncrease
     */
    public function testCalculateBalance(): void
    {
        $manager = new ManagerDb();

        $manager->increase(1, 50);
        $manager->increase(2, 50);
        $manager->decrease(1, 25);

        self::assertEquals(25, $manager->calculateBalance(1));
    }

    /**
     * @see https://github.com/nazbav/yii2-account-balance/issues/11
     *
     * @depends testIncrease
     */
    public function testSkipAutoIncrement(): void
    {
        $manager = new ManagerDb();

        $manager->transfer(
            1,
            2,
            10,
            [
                'id' => 123456789,
            ],
        );
        $transaction = $this->getLastTransaction();
        self::assertStringContainsString('123456789', $transaction['data']);
    }

    public function testTableNotFoundMessageContainsTableName(): void
    {
        $manager = new ManagerDb();
        $manager->accountTable = 'MissingBalanceAccountTable';

        try {
            $manager->getAccountIdAttribute();
            self::fail('Ожидалось исключение при отсутствии таблицы счёта.');
        } catch (\yii\base\InvalidConfigException $invalidConfigException) {
            self::assertStringContainsString('MissingBalanceAccountTable', $invalidConfigException->getMessage());
        }
    }

    public function testMissingTransactionColumnMessageContainsTableName(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->requireOperationId = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->operationIdAttribute = 'missingOperationIdColumn';

        try {
            $manager->increase(['userId' => 404], 10, ['missingOperationIdColumn' => 'op-404']);
            self::fail('Ожидалось исключение для отсутствующей колонки transaction table.');
        } catch (\yii\base\InvalidConfigException $invalidConfigException) {
            self::assertStringContainsString('BalanceTransaction', $invalidConfigException->getMessage());
            self::assertStringContainsString('missingOperationIdColumn', $invalidConfigException->getMessage());
        }
    }

    public function testGetDbConnectionCanBeOverriddenFromDescendant(): void
    {
        $manager = new class () extends ManagerDb {
            public bool $overriddenCall = false;

            protected function getDbConnection(): \yii\db\Connection
            {
                $this->overriddenCall = true;

                return parent::getDbConnection();
            }
        };

        $manager->init();

        self::assertTrue($manager->overriddenCall);
    }

    public function testPrivateSafeColumnValidationRejectsMalformedNames(): void
    {
        $manager = new ManagerDb();

        try {
            $this->invokePrivateMethod($manager, 'ensureSafeColumnName', ['amount;DROP']);
            self::fail('Ожидалось исключение на некорректном имени колонки.');
        } catch (\yii\base\InvalidConfigException $invalidConfigException) {
            self::assertStringContainsString('amount;DROP', $invalidConfigException->getMessage());
        }
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokePrivateMethod(object $object, string $method, array $arguments): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $arguments);
    }
}
