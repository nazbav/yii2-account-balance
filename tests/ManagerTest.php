<?php

declare(strict_types=1);

namespace nazbav\tests\unit\balance;

use nazbav\balance\BalanceRules;
use nazbav\balance\Manager;
use nazbav\balance\TransactionEvent;
use nazbav\tests\unit\balance\data\ManagerMock;

class ManagerTest extends TestCase
{
    public function testIncrease(): void
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
    public function testDecrease(): void
    {
        $manager = new ManagerMock();

        $manager->decrease(1, 50);

        $transaction = $manager->getLastTransaction();
        $this->assertEquals(-50, $transaction['amount']);
    }

    public function testIncreaseRejectsNonPositiveAmount(): void
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1, 0);
    }

    public function testIncreaseRejectsInfiniteAmount(): void
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1, INF);
    }

    public function testIncreaseRejectsInfiniteAmountEvenWithoutPositiveRule(): void
    {
        $manager = new ManagerMock();
        $manager->requirePositiveAmount = false;

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1, INF);
    }

    public function testDecreaseRejectsNonPositiveAmount(): void
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->decrease(1, -10);
    }

    /**
     * @depends testIncrease
     */
    public function testTransfer(): void
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

    public function testTransferRejectsSameAccount(): void
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->transfer(10, 10, 5);
    }

    /**
     * @depends testIncrease
     */
    public function testDateAttributeValue(): void
    {
        $manager = new ManagerMock();

        $now = time();
        $manager->increase(1, 10);
        $transaction = $manager->getLastTransaction();
        $this->assertTrue($transaction['date'] >= $now);

        $manager->dateAttributeValue = fn (): string => 'callback';
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
    public function testAutoCreateAccount(): void
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
    public function testIncreaseAccountBalance(): void
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
    public function testSaveExtraAccount(): void
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
    public function testRevert(): void
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

    public function testRevertDecreaseTransaction(): void
    {
        $manager = new ManagerMock();
        $manager->accountBalanceAttribute = 'balance';

        $accountId = 3;
        $manager->increase($accountId, 50);
        $decreaseTransactionId = $manager->decrease($accountId, 10);
        $manager->revert($decreaseTransactionId);

        $this->assertEquals(50, $manager->accountBalances[$accountId]);
    }

    public function testForbidNegativeBalanceRequiresBalanceAttribute(): void
    {
        $manager = new ManagerMock();
        $manager->forbidNegativeBalance = true;

        $this->expectException('yii\base\InvalidConfigException');
        $manager->decrease(1, 1);
    }

    public function testSetAndGetBalanceRules(): void
    {
        $manager = new ManagerMock();
        $manager->setBalanceRules(new BalanceRules(
            requirePositiveAmount: false,
            forbidTransferToSameAccount: false,
            forbidNegativeBalance: true,
            minimumAllowedBalance: -100,
        ));

        $this->assertFalse($manager->requirePositiveAmount);
        $this->assertFalse($manager->forbidTransferToSameAccount);
        $this->assertTrue($manager->forbidNegativeBalance);
        $this->assertSame(-100, $manager->minimumAllowedBalance);

        $rules = $manager->getBalanceRules();
        $this->assertFalse($rules->requirePositiveAmount);
        $this->assertFalse($rules->forbidTransferToSameAccount);
        $this->assertTrue($rules->forbidNegativeBalance);
        $this->assertSame(-100, $rules->minimumAllowedBalance);
    }

    public function testEnableStrictMode(): void
    {
        $manager = new ManagerMock();
        $manager->enableStrictMode();

        $this->assertTrue($manager->requirePositiveAmount);
        $this->assertTrue($manager->forbidTransferToSameAccount);
        $this->assertTrue($manager->forbidNegativeBalance);
        $this->assertSame(0, $manager->minimumAllowedBalance);
    }

    public function testSetBalanceRulesRejectsInfiniteMinimum(): void
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->setBalanceRules(BalanceRules::strict(INF));
    }

    /**
     * @depends testIncrease
     */
    public function testEventBeforeCreateTransaction(): void
    {
        $manager = new ManagerMock();
        $manager->on(Manager::EVENT_BEFORE_CREATE_TRANSACTION, function ($event): void {
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
    public function testEventAfterCreateTransaction(): void
    {
        $manager = new ManagerMock();
        $eventTransactionId = null;
        $manager->on(Manager::EVENT_AFTER_CREATE_TRANSACTION, function ($event) use (&$eventTransactionId): void {
            /* @var $event TransactionEvent */
            $eventTransactionId = $event->transactionId;
        });

        $manager->increase(1, 50);

        $transaction = $manager->getLastTransaction();
        $this->assertNotNull($eventTransactionId);
        $this->assertSame($eventTransactionId, $transaction['id']);
    }
}
