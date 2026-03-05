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
        self::assertEquals(50, $transaction['amount']);

        $manager->increase(1, 50, ['extra' => 'custom']);
        $transaction = $manager->getLastTransaction();
        self::assertEquals('custom', $transaction['extra']);
    }

    /**
     * @depends testIncrease
     */
    public function testDecrease(): void
    {
        $manager = new ManagerMock();

        $manager->decrease(1, 50);

        $transaction = $manager->getLastTransaction();
        self::assertEquals(-50, $transaction['amount']);
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
        self::assertEquals(-10, $transactions[0]['amount']);
        self::assertEquals(10, $transactions[1]['amount']);

        $manager->transfer(1, 2, 10, ['extra' => 'custom']);
        $transaction = $manager->getLastTransaction();
        self::assertEquals('custom', $transaction['extra']);
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
        self::assertTrue($transaction['date'] >= $now);

        $manager->dateAttributeValue = fn (): string => 'callback';
        $manager->increase(1, 10);
        $transaction = $manager->getLastTransaction();
        self::assertEquals('callback', $transaction['date']);

        $manager->dateAttributeValue = new \DateTime();
        $manager->increase(1, 10);
        $transaction = $manager->getLastTransaction();
        self::assertEquals($manager->dateAttributeValue, $transaction['date']);
    }

    /**
     * @depends testIncrease
     */
    public function testAutoCreateAccount(): void
    {
        $manager = new ManagerMock();

        $manager->autoCreateAccount = true;
        $manager->increase(['userId' => 5], 10);
        self::assertCount(1, $manager->accounts);

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
        self::assertEquals($amount, $manager->accountBalances[$accountId]);

        $manager->accountBalanceAttribute = null;
        $accountId = 20;
        $amount = 40;
        $manager->increase($accountId, $amount);
        self::assertArrayNotHasKey($accountId, $manager->accountBalances);
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
        self::assertEquals(2, $transactions[0][$manager->extraAccountLinkAttribute]);
        self::assertEquals(1, $transactions[1][$manager->extraAccountLinkAttribute]);
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

        self::assertEquals(0, $manager->accountBalances[$accountId]);

        $fromId = 10;
        $toId = 20;
        $transactionIds = $manager->transfer($fromId, $toId, 10);
        $manager->revert($transactionIds[0]);

        self::assertEquals(0, $manager->accountBalances[$fromId]);
        self::assertEquals(0, $manager->accountBalances[$toId]);
    }

    public function testRevertDecreaseTransaction(): void
    {
        $manager = new ManagerMock();
        $manager->accountBalanceAttribute = 'balance';

        $accountId = 3;
        $manager->increase($accountId, 50);
        $decreaseTransactionId = $manager->decrease($accountId, 10);
        $manager->revert($decreaseTransactionId);

        self::assertEquals(50, $manager->accountBalances[$accountId]);
    }

    public function testForbidNegativeBalanceRequiresBalanceAttribute(): void
    {
        $manager = new ManagerMock();
        $manager->forbidNegativeBalance = true;

        $this->expectException('yii\base\InvalidConfigException');
        $manager->decrease(1, 1);
    }

    public function testDuplicateOperationIdForSameAccountIsRejected(): void
    {
        $manager = new ManagerMock();
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;

        $manager->increase(1001, 30, ['operationId' => 'bonus:welcome:1001']);

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1001, 30, ['operationId' => 'bonus:welcome:1001']);
    }

    public function testDuplicateOperationIdAllowedForDifferentAccounts(): void
    {
        $manager = new ManagerMock();
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;

        $manager->increase(1001, 30, ['operationId' => 'campaign:shared']);
        $manager->increase(1002, 30, ['operationId' => 'campaign:shared']);

        self::assertCount(2, $manager->transactions);
    }

    public function testRequireOperationIdRejectsMissingValue(): void
    {
        $manager = new ManagerMock();
        $manager->requireOperationId = true;

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1001, 30, []);
    }

    public function testRequireOperationIdRejectsInvalidValueType(): void
    {
        $manager = new ManagerMock();
        $manager->requireOperationId = true;

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1001, 30, ['operationId' => ['nested' => true]]);
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

        self::assertFalse($manager->requirePositiveAmount);
        self::assertFalse($manager->forbidTransferToSameAccount);
        self::assertTrue($manager->forbidNegativeBalance);
        self::assertSame(-100, $manager->minimumAllowedBalance);

        $rules = $manager->getBalanceRules();
        self::assertFalse($rules->requirePositiveAmount);
        self::assertFalse($rules->forbidTransferToSameAccount);
        self::assertTrue($rules->forbidNegativeBalance);
        self::assertSame(-100, $rules->minimumAllowedBalance);
    }

    public function testEnableStrictMode(): void
    {
        $manager = new ManagerMock();
        $manager->enableStrictMode();

        self::assertTrue($manager->requirePositiveAmount);
        self::assertTrue($manager->forbidTransferToSameAccount);
        self::assertTrue($manager->forbidNegativeBalance);
        self::assertSame(0, $manager->minimumAllowedBalance);
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
        self::assertEquals('event', $transaction['extra']);
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
        self::assertNotNull($eventTransactionId);
        self::assertSame($eventTransactionId, $transaction['id']);
    }
}
