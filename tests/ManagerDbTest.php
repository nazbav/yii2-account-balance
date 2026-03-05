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
    protected function setupTestDbData()
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
     * @return array данные последней сохранённой транзакции.
     */
    protected function getLastTransaction()
    {
        $transaction = (new Query())
            ->from('BalanceTransaction')
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->one();
        $this->assertIsArray($transaction);
        return $transaction;
    }

    // Набор тестов.

    public function testIncrease(): void
    {
        $manager = new ManagerDb();

        $manager->increase(1, 50);

        $transaction = $this->getLastTransaction();
        $this->assertEquals(50, $transaction['amount']);

        $manager->increase(1, 50, ['extra' => 'custom']);
        $transaction = $this->getLastTransaction();
        $this->assertStringContainsString('custom', $transaction['data']);
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
        $this->assertCount(1, $accounts);
        $this->assertEquals(5, $accounts[0]['userId']);

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
        $this->assertIsArray($account);

        $this->assertEquals($amount, $account['balance']);

        // Обновление баланса существующего счёта.
        $amount = 50;
        $manager->increase(['userId' => 1], $amount);
        $account = (new Query())->from('BalanceAccount')->andWhere(['userId' => 1])->one();
        $this->assertIsArray($account);
        $this->assertEquals(100, $account['balance']);
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
        $this->assertEquals($accountId, $transaction['accountId']);
        $this->assertEquals(-$amount, $transaction['amount']);
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
            $this->fail('Ожидалось исключение о недостатке средств.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString('Недостаточно средств', $invalidArgumentException->getMessage());
        }

        $account = (new Query())->from('BalanceAccount')->andWhere(['userId' => 100])->one();
        $this->assertIsArray($account);
        $this->assertEquals(30, $account['balance']);
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

        $this->assertEquals(25, $manager->calculateBalance(1));
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
        $this->assertStringContainsString('123456789', $transaction['data']);
    }
}
