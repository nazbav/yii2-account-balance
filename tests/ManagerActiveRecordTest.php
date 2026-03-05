<?php

declare(strict_types=1);

namespace nazbav\tests\unit\balance;

use nazbav\balance\ManagerActiveRecord;
use nazbav\tests\unit\balance\data\BalanceAccount;
use nazbav\tests\unit\balance\data\BalanceTransaction;
use Yii;

/**
 * @group db
 */
class ManagerActiveRecordTest extends TestCase
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
        $transaction = BalanceTransaction::find()
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->asArray(true)
            ->one();
        self::assertIsArray($transaction);
        return $transaction;
    }

    /**
     * @return ManagerActiveRecord экземпляр тестового менеджера.
     */
    protected function createManager(): \nazbav\balance\ManagerActiveRecord
    {
        $manager = new ManagerActiveRecord();
        $manager->accountClass = BalanceAccount::class;
        $manager->transactionClass = BalanceTransaction::class;
        return $manager;
    }

    // Набор тестов.

    public function testIncrease(): void
    {
        $manager = $this->createManager();

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
        $manager = $this->createManager();

        $manager->autoCreateAccount = true;
        $manager->increase(['userId' => 5], 10);
        $accounts = BalanceAccount::find()->all();
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
        $manager = $this->createManager();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';

        $amount = 50;
        $manager->increase(['userId' => 1], $amount);
        $account = BalanceAccount::find()->andWhere(['userId' => 1])->one();
        self::assertNotNull($account);

        self::assertEquals($amount, $account['balance']);
    }

    /**
     * @depends testIncrease
     */
    public function testRevert(): void
    {
        $manager = $this->createManager();

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
        $manager = $this->createManager();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->transfer(1, 1, 10);
    }

    public function testRevertMissingTransactionContainsIdInMessage(): void
    {
        $manager = $this->createManager();

        try {
            $manager->revert(999999);
            self::fail('Ожидалось исключение при отсутствии транзакции.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('999999', $invalidArgumentException->getMessage());
        }
    }

    public function testForbidNegativeBalance(): void
    {
        $manager = $this->createManager();
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

        $account = BalanceAccount::find()->andWhere(['userId' => 100])->one();
        self::assertNotNull($account);
        self::assertEquals(30, $account['balance']);
    }

    public function testForbidNegativeBalanceAllowsDecreaseWhenFundsAreEnough(): void
    {
        $manager = $this->createManager();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidNegativeBalance = true;
        $manager->minimumAllowedBalance = 0;

        $manager->increase(['userId' => 101], 30);
        $manager->decrease(['userId' => 101], 10);

        $account = BalanceAccount::find()->andWhere(['userId' => 101])->one();
        self::assertNotNull($account);
        self::assertSame(20, (int) $account['balance']);
    }

    public function testForbidNegativeBalanceDoesNotNeedMinimumForZeroAmount(): void
    {
        $manager = $this->createManager();
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidNegativeBalance = true;
        $manager->minimumAllowedBalance = INF;
        $manager->requirePositiveAmount = false;

        $manager->decrease(1, 0);

        $transaction = $this->getLastTransaction();
        self::assertSame(1, (int) $transaction['accountId']);
        self::assertSame(0, (int) $transaction['amount']);
    }

    public function testPositiveBalanceUpdateTouchesOnlyTargetAccount(): void
    {
        $manager = $this->createManager();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidNegativeBalance = true;

        $manager->increase(['userId' => 201], 10);
        $manager->increase(['userId' => 202], 10);
        $manager->increase(['userId' => 201], 5);

        $first = BalanceAccount::find()->andWhere(['userId' => 201])->one();
        $second = BalanceAccount::find()->andWhere(['userId' => 202])->one();
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(15, (int) $first['balance']);
        self::assertSame(10, (int) $second['balance']);
    }

    public function testInsufficientFundsMessageContainsAccountAndMinimum(): void
    {
        $manager = $this->createManager();
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

    public function testDecreaseRollsBackBalanceWhenTransactionModelIsInvalid(): void
    {
        $manager = $this->createManager();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';

        $manager->increase(['userId' => 777], 50);

        $manager->transactionClass = \stdClass::class;
        try {
            $manager->decrease(['userId' => 777], 10);
            self::fail('Ожидалось исключение при неверном классе транзакций.');
        } catch (\yii\base\InvalidConfigException) {
            // Ожидаемая ветка.
        }

        $account = BalanceAccount::find()->andWhere(['userId' => 777])->one();
        self::assertNotNull($account);
        self::assertEquals(50, $account['balance']);
    }

    public function testAutoCreateAccountRejectsUnknownFilterAttributes(): void
    {
        $manager = $this->createManager();
        $manager->autoCreateAccount = true;

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(['unknownAttr' => 1], 10);
    }

    public function testDuplicateOperationIdForSameAccountIsRejected(): void
    {
        $manager = $this->createManager();
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

        $account = BalanceAccount::find()->andWhere(['userId' => 9001])->one();
        self::assertNotNull($account);
        self::assertEquals(40, $account['balance']);

        $transactions = BalanceTransaction::find()
            ->where(['operationId' => 'bonus:welcome:9001'])
            ->asArray()
            ->all();
        self::assertCount(1, $transactions);
        self::assertSame(40, (int) $transactions[0]['amount']);
    }

    public function testDuplicateOperationIdAllowedForDifferentAccounts(): void
    {
        $manager = $this->createManager();
        $manager->autoCreateAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;

        $manager->increase(['userId' => 9101], 10, ['operationId' => 'campaign:shared']);
        $manager->increase(['userId' => 9102], 10, ['operationId' => 'campaign:shared']);

        $rows = BalanceTransaction::find()->where(['operationId' => 'campaign:shared'])->asArray()->all();
        self::assertCount(2, $rows);
        self::assertSame(10, (int) $rows[0]['amount']);
        self::assertSame(10, (int) $rows[1]['amount']);
    }

    public function testRequireOperationIdRejectsMissingValue(): void
    {
        $manager = $this->createManager();
        $manager->requireOperationId = true;

        try {
            $manager->increase(1, 10);
            self::fail('Ожидалось исключение при отсутствии operationId.');
        } catch (\yii\base\InvalidArgumentException) {
            // Ожидаемая ветка.
        }

        self::assertCount(0, BalanceTransaction::find()->all());
    }

    public function testOperationIdAttributeRejectsUnsafeColumnName(): void
    {
        $manager = $this->createManager();
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

        self::assertCount(0, BalanceTransaction::find()->all());
    }

    public function testOperationIdSqlPayloadIsHandledAsLiteralValue(): void
    {
        $manager = $this->createManager();
        $manager->autoCreateAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;

        $manager->increase(['userId' => 9202], 10, ['operationId' => 'safe-operation']);
        $manager->increase(['userId' => 9202], 10, ['operationId' => "payload' OR 1=1 --"]);

        $rows = BalanceTransaction::find()->where(['accountId' => 1])->orderBy(['id' => SORT_ASC])->asArray()->all();
        self::assertCount(2, $rows);
        self::assertSame('safe-operation', $rows[0]['operationId']);
        self::assertSame("payload' OR 1=1 --", $rows[1]['operationId']);
    }

    public function testCalculateBalanceRejectsUnsafeAmountColumnName(): void
    {
        $manager = $this->createManager();
        $manager->amountAttribute = 'amount) OR 1=1 --';

        $this->expectException('yii\base\InvalidConfigException');
        $manager->calculateBalance(1);
    }

    public function testCalculateBalanceRejectsMissingAmountColumnName(): void
    {
        $manager = $this->createManager();
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
        $manager = $this->createManager();
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
        $manager = $this->createManager();
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

        self::assertCount(0, BalanceTransaction::find()->all());
    }

    public function testDuplicateOperationCheckRejectsMissingOperationIdColumnName(): void
    {
        $manager = $this->createManager();
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
        $manager = $this->createManager();
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
        $manager = new class () extends ManagerActiveRecord {
            /**
             * @param array<string, mixed> $attributes
             */
            public function createAccountPublic(array $attributes): mixed
            {
                return $this->createAccount($attributes);
            }
        };
        $manager->accountClass = BalanceAccount::class;
        $manager->transactionClass = BalanceTransaction::class;

        $firstId = $manager->createAccountPublic(['userId' => 901]);
        $secondId = $manager->createAccountPublic(['userId' => 901]);

        self::assertSame((string) $firstId, (string) $secondId);
    }

    public function testCreateAccountBypassesValidationRules(): void
    {
        $db = Yii::$app->getDb();
        try {
            $db->createCommand()->dropTable('StrictBalanceAccount')->execute();
        } catch (\Throwable) {
            // Таблица может отсутствовать.
        }
        $db->createCommand()->createTable('StrictBalanceAccount', [
            'id' => 'pk',
            'userId' => 'integer',
            'requiredToken' => 'string',
            'balance' => 'integer DEFAULT 0',
        ])->execute();

        $accountModel = new class () extends \yii\db\ActiveRecord {
            public static function tableName(): string
            {
                return 'StrictBalanceAccount';
            }

            public function rules(): array
            {
                return [[['requiredToken'], 'required']];
            }
        };
        $accountClass = $accountModel::class;

        $manager = new class () extends ManagerActiveRecord {
            /**
             * @param array<string, mixed> $attributes
             */
            public function createAccountPublic(array $attributes): mixed
            {
                return $this->createAccount($attributes);
            }
        };
        $manager->accountClass = $accountClass;
        $manager->transactionClass = BalanceTransaction::class;

        $id = $manager->createAccountPublic(['userId' => 3001]);

        self::assertNotNull($id);
        self::assertSame(1, (int) (new \yii\db\Query())->from('StrictBalanceAccount')->count('*'));
    }

    public function testCreateTransactionBypassesValidationRules(): void
    {
        $db = Yii::$app->getDb();
        try {
            $db->createCommand()->dropTable('StrictBalanceTransaction')->execute();
        } catch (\Throwable) {
            // Таблица может отсутствовать.
        }
        $db->createCommand()->createTable('StrictBalanceTransaction', [
            'id' => 'pk',
            'date' => 'integer',
            'accountId' => 'integer',
            'amount' => 'integer',
            'requiredToken' => 'string',
            'data' => 'text',
        ])->execute();

        $transactionModel = new class () extends \yii\db\ActiveRecord {
            public static function tableName(): string
            {
                return 'StrictBalanceTransaction';
            }

            public function rules(): array
            {
                return [[['requiredToken'], 'required']];
            }
        };
        $transactionClass = $transactionModel::class;

        $manager = new class () extends ManagerActiveRecord {
            /**
             * @param array<string, mixed> $attributes
             */
            public function createTransactionPublic(array $attributes): mixed
            {
                return $this->createTransaction($attributes);
            }
        };
        $manager->accountClass = BalanceAccount::class;
        $manager->transactionClass = $transactionClass;

        $id = $manager->createTransactionPublic([
            'date' => time(),
            'accountId' => 3001,
            'amount' => 25,
        ]);

        self::assertNotNull($id);
        self::assertSame(1, (int) (new \yii\db\Query())->from('StrictBalanceTransaction')->count('*'));
    }

    public function testCreateDbTransactionStartsAndReturnsTransaction(): void
    {
        $manager = new class () extends ManagerActiveRecord {
            public function createDbTransactionPublic(): ?\yii\db\Transaction
            {
                return $this->createDbTransaction();
            }
        };
        $manager->accountClass = BalanceAccount::class;
        $manager->transactionClass = BalanceTransaction::class;

        $transaction = $manager->createDbTransactionPublic();
        self::assertInstanceOf(\yii\db\Transaction::class, $transaction);
        $transaction->rollBack();
    }

    public function testCreateDbTransactionReturnsNullWhenTransactionIsAlreadyOpen(): void
    {
        $manager = new class () extends ManagerActiveRecord {
            public function createDbTransactionPublic(): ?\yii\db\Transaction
            {
                return $this->createDbTransaction();
            }
        };
        $manager->accountClass = BalanceAccount::class;
        $manager->transactionClass = BalanceTransaction::class;

        $openTransaction = Yii::$app->db->beginTransaction();
        try {
            $transaction = $manager->createDbTransactionPublic();
            self::assertNull($transaction);
        } finally {
            $openTransaction->rollBack();
        }
    }

    public function testSkipAutoIncrementPrimaryKeyInActiveRecord(): void
    {
        $manager = $this->createManager();

        $manager->increase(1, 10, ['id' => 9999999]);

        $transaction = $this->getLastTransaction();

        self::assertNotEquals(9999999, $transaction['id']);
        self::assertStringContainsString('9999999', $transaction['data']);
    }

    /**
     * @depends testIncrease
     */
    public function testCalculateBalance(): void
    {
        $manager = $this->createManager();

        $manager->increase(1, 50);
        $manager->increase(2, 50);
        $manager->decrease(1, 25);

        self::assertEquals(25, $manager->calculateBalance(1));
    }

    public function testInvalidActiveRecordPropertyMessageContainsPropertyName(): void
    {
        $manager = new ManagerActiveRecord();
        $manager->accountClass = '';
        $manager->transactionClass = BalanceTransaction::class;

        try {
            $manager->increase(['userId' => 501], 10);
            self::fail('Ожидалось исключение для невалидного accountClass.');
        } catch (\yii\base\InvalidConfigException $invalidConfigException) {
            self::assertStringContainsString('accountClass', $invalidConfigException->getMessage());
        }
    }

    public function testDetectWritableAttributeNamesReturnsAttributesWithoutSchema(): void
    {
        $manager = $this->createManager();

        $model = new class () extends \yii\db\ActiveRecord {
            public static function tableName(): string
            {
                return 'MissingSchemaTable';
            }

            /**
             * @return array<int, string>
             */
            public function attributes(): array
            {
                return ['id', 'code', 'amount'];
            }
        };

        $attributes = $this->invokePrivateMethod($manager, 'detectWritableAttributeNames', [$model]);

        self::assertSame(['id', 'code', 'amount'], $attributes);
    }

    public function testDetectWritableAttributeNamesKeepsManualPrimaryKey(): void
    {
        $db = Yii::$app->getDb();
        try {
            $db->createCommand()->dropTable('ManualPkTransaction')->execute();
        } catch (\Throwable) {
            // Таблица может отсутствовать.
        }
        $db->createCommand()->createTable('ManualPkTransaction', [
            'code' => 'string PRIMARY KEY',
            'amount' => 'integer',
        ])->execute();

        $manager = $this->createManager();
        $model = new class () extends \yii\db\ActiveRecord {
            public static function tableName(): string
            {
                return 'ManualPkTransaction';
            }
        };

        $attributes = $this->invokePrivateMethod($manager, 'detectWritableAttributeNames', [$model]);

        self::assertContains('code', $attributes);
    }

    public function testDetectWritableAttributeNamesReindexesAfterAutoIncrementRemoval(): void
    {
        $manager = $this->createManager();
        $model = new BalanceTransaction();

        $attributes = $this->invokePrivateMethod($manager, 'detectWritableAttributeNames', [$model]);

        self::assertSame(array_keys($attributes), array_keys(array_values($attributes)));
    }

    public function testMissingSchemaInTransactionColumnValidationThrowsInvalidConfig(): void
    {
        $manager = new ManagerActiveRecord();
        $manager->accountClass = BalanceAccount::class;
        $manager->transactionClass = (new class () extends \yii\db\ActiveRecord {
            public static function tableName(): string
            {
                return 'MissingSchemaTransactionTable';
            }
        })::class;
        $manager->amountAttribute = 'amount';

        try {
            $manager->calculateBalance(1);
            self::fail('Ожидалось исключение при отсутствии схемы таблицы транзакций.');
        } catch (\yii\base\InvalidConfigException $invalidConfigException) {
            self::assertStringContainsString('MissingSchemaTransactionTable', $invalidConfigException->getMessage());
        }
    }

    public function testPrivateSafeColumnValidationRejectsMalformedNames(): void
    {
        $manager = $this->createManager();

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
