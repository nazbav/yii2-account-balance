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
}
