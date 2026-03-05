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
    }

    public function testRequireOperationIdRejectsMissingValue(): void
    {
        $manager = new ManagerDb();
        $manager->requireOperationId = true;

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1, 10);
    }

    public function testOperationIdAttributeRejectsUnsafeColumnName(): void
    {
        $manager = new ManagerDb();
        $manager->autoCreateAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;
        $manager->operationIdAttribute = 'operationId; DROP TABLE BalanceTransaction;--';

        $this->expectException('yii\base\InvalidConfigException');
        $manager->increase(['userId' => 9201], 10, ['operationId; DROP TABLE BalanceTransaction;--' => 'bad']);
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
}
