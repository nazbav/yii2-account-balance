<?php

declare(strict_types=1);


namespace nazbav\tests\unit\balance;

use Yii;
use nazbav\balance\ManagerActiveRecord;
use nazbav\tests\unit\balance\data\BalanceAccount;
use nazbav\tests\unit\balance\data\BalanceTransaction;

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
    protected function setupTestDbData()
    {
        $db = Yii::$app->getDb();

        // Структура таблиц.

        $table = 'BalanceAccount';
        try {
            $db->createCommand()->dropTable($table)->execute();
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        $transaction = BalanceTransaction::find()
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->asArray(true)
            ->one();
        $this->assertIsArray($transaction);
        return $transaction;
    }

    /**
     * @return ManagerActiveRecord экземпляр тестового менеджера.
     */
    protected function createManager()
    {
        $manager = new ManagerActiveRecord();
        $manager->accountClass = BalanceAccount::class;
        $manager->transactionClass = BalanceTransaction::class;
        return $manager;
    }

    // Набор тестов.

    public function testIncrease()
    {
        $manager = $this->createManager();

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
    public function testAutoCreateAccount()
    {
        $manager = $this->createManager();

        $manager->autoCreateAccount = true;
        $manager->increase(['userId' => 5], 10);
        $accounts = BalanceAccount::find()->all();
        $this->assertCount(1, $accounts);
        $this->assertEquals(5, $accounts[0]['userId']);

        $manager->autoCreateAccount = false;
        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(['userId' => 10], 10);
    }

    /**
     * @depends testAutoCreateAccount
     */
    public function testIncreaseAccountBalance()
    {
        $manager = $this->createManager();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';

        $amount = 50;
        $manager->increase(['userId' => 1], $amount);
        $account = BalanceAccount::find()->andWhere(['userId' => 1])->one();
        $this->assertNotNull($account);

        $this->assertEquals($amount, $account['balance']);
    }

    /**
     * @depends testIncrease
     */
    public function testRevert()
    {
        $manager = $this->createManager();

        $accountId = 1;
        $amount = 10;
        $transactionId = $manager->increase($accountId, $amount);
        $manager->revert($transactionId);

        $transaction = $this->getLastTransaction();
        $this->assertEquals($accountId, $transaction['accountId']);
        $this->assertEquals(-$amount, $transaction['amount']);
    }

    public function testTransferRejectsSameAccount()
    {
        $manager = $this->createManager();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->transfer(1, 1, 10);
    }

    public function testForbidNegativeBalance()
    {
        $manager = $this->createManager();
        $manager->autoCreateAccount = true;
        $manager->accountBalanceAttribute = 'balance';
        $manager->forbidNegativeBalance = true;
        $manager->minimumAllowedBalance = 0;

        $manager->increase(['userId' => 100], 30);

        try {
            $manager->decrease(['userId' => 100], 50);
            $this->fail('Ожидалось исключение о недостатке средств.');
        } catch (\yii\base\InvalidArgumentException $e) {
            $this->assertStringContainsString('Недостаточно средств', $e->getMessage());
        }

        $account = BalanceAccount::find()->andWhere(['userId' => 100])->one();
        $this->assertNotNull($account);
        $this->assertEquals(30, $account['balance']);
    }

    public function testSkipAutoIncrementPrimaryKeyInActiveRecord()
    {
        $manager = $this->createManager();

        $manager->increase(1, 10, ['id' => 9999999]);
        $transaction = $this->getLastTransaction();

        $this->assertNotEquals(9999999, $transaction['id']);
        $this->assertStringContainsString('9999999', $transaction['data']);
    }

    /**
     * @depends testIncrease
     */
    public function testCalculateBalance()
    {
        $manager = $this->createManager();

        $manager->increase(1, 50);
        $manager->increase(2, 50);
        $manager->decrease(1, 25);

        $this->assertEquals(25, $manager->calculateBalance(1));
    }
}
