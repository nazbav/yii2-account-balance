<?php

declare(strict_types=1);


namespace nazbav\tests\unit\balance;

use nazbav\balance\Manager;
use nazbav\balance\TransactionEvent;
use nazbav\tests\unit\balance\data\ManagerMock;

class ManagerTest extends TestCase
{
    public function testIncrease()
    {
        $manager = new ManagerMock();

        $manager->increase(1, 50);
        $transaction = $manager->getLastTransaction();
        $this->assertEquals(50, $transaction['amount']);

        $manager->increase(1, 50, ['extra' => 'custom']);
        $transaction = $manager->getLastTransaction();
        $this->assertEquals('custom', $transaction['extra']);
    }

    /**
     * @depends testIncrease
     */
    public function testDecrease()
    {
        $manager = new ManagerMock();

        $manager->decrease(1, 50);
        $transaction = $manager->getLastTransaction();
        $this->assertEquals(-50, $transaction['amount']);
    }

    public function testIncreaseRejectsNonPositiveAmount()
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1, 0);
    }

    public function testIncreaseRejectsInfiniteAmount()
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1, INF);
    }

    public function testDecreaseRejectsNonPositiveAmount()
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->decrease(1, -10);
    }

    /**
     * @depends testIncrease
     */
    public function testTransfer()
    {
        $manager = new ManagerMock();

        $manager->transfer(1, 2, 10);
        $transactions = $manager->getLastTransactionPair();
        $this->assertEquals(-10, $transactions[0]['amount']);
        $this->assertEquals(10, $transactions[1]['amount']);

        $manager->transfer(1, 2, 10, ['extra' => 'custom']);
        $transaction = $manager->getLastTransaction();
        $this->assertEquals('custom', $transaction['extra']);
    }

    public function testTransferRejectsSameAccount()
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->transfer(10, 10, 5);
    }

    /**
     * @depends testIncrease
     */
    public function testDateAttributeValue()
    {
        $manager = new ManagerMock();

        $now = time();
        $manager->increase(1, 10);
        $transaction = $manager->getLastTransaction();
        $this->assertTrue($transaction['date'] >= $now);

        $manager->dateAttributeValue = function() {
            return 'callback';
        };
        $manager->increase(1, 10);
        $transaction = $manager->getLastTransaction();
        $this->assertEquals('callback', $transaction['date']);

        $manager->dateAttributeValue = new \DateTime();
        $manager->increase(1, 10);
        $transaction = $manager->getLastTransaction();
        $this->assertEquals($manager->dateAttributeValue, $transaction['date']);
    }

    /**
     * @depends testIncrease
     */
    public function testAutoCreateAccount()
    {
        $manager = new ManagerMock();

        $manager->autoCreateAccount = true;
        $manager->increase(['userId' => 5], 10);
        $this->assertCount(1, $manager->accounts);

        $manager->autoCreateAccount = false;
        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(['userId' => 10], 10);
    }

    /**
     * @depends testIncrease
     */
    public function testIncreaseAccountBalance()
    {
        $manager = new ManagerMock();

        $manager->accountBalanceAttribute = 'balance';
        $accountId = 10;
        $amount = 50;
        $manager->increase($accountId, $amount);
        $this->assertEquals($amount, $manager->accountBalances[$accountId]);

        $manager->accountBalanceAttribute = null;
        $accountId = 20;
        $amount = 40;
        $manager->increase($accountId, $amount);
        $this->assertArrayNotHasKey($accountId, $manager->accountBalances);
    }

    /**
     * @depends testTransfer
     */
    public function testSaveExtraAccount()
    {
        $manager = new ManagerMock();

        $manager->extraAccountLinkAttribute = 'extraAccountId';
        $manager->transfer(1, 2, 10);
        $transactions = $manager->getLastTransactionPair();
        $this->assertEquals(2, $transactions[0][$manager->extraAccountLinkAttribute]);
        $this->assertEquals(1, $transactions[1][$manager->extraAccountLinkAttribute]);
    }

    /**
     * @depends testIncreaseAccountBalance
     * @depends testTransfer
     */
    public function testRevert()
    {
        $manager = new ManagerMock();
        $manager->accountBalanceAttribute = 'balance';
        $manager->extraAccountLinkAttribute = 'extraAccountId';

        $accountId = 1;
        $transactionId = $manager->increase($accountId, 10);
        $manager->revert($transactionId);

        $this->assertEquals(0, $manager->accountBalances[$accountId]);

        $fromId = 10;
        $toId = 20;
        $transactionIds = $manager->transfer($fromId, $toId, 10);
        $manager->revert($transactionIds[0]);

        $this->assertEquals(0, $manager->accountBalances[$fromId]);
        $this->assertEquals(0, $manager->accountBalances[$toId]);
    }

    public function testRevertDecreaseTransaction()
    {
        $manager = new ManagerMock();
        $manager->accountBalanceAttribute = 'balance';

        $accountId = 3;
        $manager->increase($accountId, 50);
        $decreaseTransactionId = $manager->decrease($accountId, 10);
        $manager->revert($decreaseTransactionId);

        $this->assertEquals(50, $manager->accountBalances[$accountId]);
    }

    public function testForbidNegativeBalanceRequiresBalanceAttribute()
    {
        $manager = new ManagerMock();
        $manager->forbidNegativeBalance = true;

        $this->expectException('yii\base\InvalidConfigException');
        $manager->decrease(1, 1);
    }

    /**
     * @depends testIncrease
     */
    public function testEventBeforeCreateTransaction()
    {
        $manager = new ManagerMock();
        $manager->on(Manager::EVENT_BEFORE_CREATE_TRANSACTION, function ($event) {
            /* @var $event TransactionEvent */
            $event->transactionData['extra'] = 'event';
        });

        $manager->increase(1, 50);
        $transaction = $manager->getLastTransaction();
        $this->assertEquals('event', $transaction['extra']);
    }

    /**
     * @depends testIncrease
     */
    public function testEventAfterCreateTransaction()
    {
        $manager = new ManagerMock();
        $eventTransactionId = null;
        $manager->on(Manager::EVENT_AFTER_CREATE_TRANSACTION, function ($event) use (&$eventTransactionId) {
            /* @var $event TransactionEvent */
            $eventTransactionId = $event->transactionId;
        });

        $manager->increase(1, 50);
        $transaction = $manager->getLastTransaction();
        $this->assertNotNull($eventTransactionId);
        $this->assertSame($eventTransactionId, $transaction['id']);
    }
}
