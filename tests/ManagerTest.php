<?php

declare(strict_types=1);

namespace nazbav\tests\unit\balance;

use nazbav\balance\BalanceRules;
use nazbav\balance\Manager;
use nazbav\balance\TransactionEvent;
use nazbav\tests\unit\balance\data\ManagerMock;
use Yii;

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

    public function testTransferRejectsZeroAmount(): void
    {
        $manager = new ManagerMock();

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->transfer(10, 11, 0);
    }

    public function testTransferValidatesAmountBeforeCallingOverriddenIncreaseDecrease(): void
    {
        $manager = new class () extends ManagerMock {
            public int $increaseCalls = 0;

            public int $decreaseCalls = 0;

            public function increase(mixed $account, int|float $amount, array $data = []): mixed
            {
                $this->increaseCalls++;

                return 'increase';
            }

            public function decrease(mixed $account, int|float $amount, array $data = []): mixed
            {
                $this->decreaseCalls++;

                return 'decrease';
            }
        };

        try {
            $manager->transfer(10, 11, 0);
            self::fail('Ожидалось исключение для нулевой суммы перевода.');
        } catch (\yii\base\InvalidArgumentException) {
            // Ожидаемая ветка.
        }

        self::assertSame(0, $manager->increaseCalls);
        self::assertSame(0, $manager->decreaseCalls);
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

    public function testAccountNotFoundErrorContainsFilter(): void
    {
        $manager = new ManagerMock();
        $manager->autoCreateAccount = false;

        try {
            $manager->increase(['userId' => 10], 10);
            self::fail('Ожидалось исключение при отсутствии счёта по фильтру.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('userId', $invalidArgumentException->getMessage());
        }
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

    public function testZeroIncreaseAllowedWhenPositiveRuleDisabledWithNegativeProtection(): void
    {
        $manager = new ManagerMock();
        $manager->requirePositiveAmount = false;
        $manager->forbidNegativeBalance = true;
        $manager->accountBalanceAttribute = null;

        $manager->increase(1, 0);

        $transaction = $manager->getLastTransaction();
        self::assertSame(0, $transaction['amount']);
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

    public function testDuplicateOperationIdErrorContainsOperationAndAccount(): void
    {
        $manager = new ManagerMock();
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;

        $manager->increase(1001, 30, ['operationId' => 'bonus:welcome:1001']);

        try {
            $manager->increase(1001, 30, ['operationId' => 'bonus:welcome:1001']);
            self::fail('Ожидалось исключение для повторного operationId.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('bonus:welcome:1001', $invalidArgumentException->getMessage());
            self::assertStringContainsString('1001', $invalidArgumentException->getMessage());
        }
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

    public function testDuplicateGuardWorksWithoutRequiredOperationId(): void
    {
        $manager = new ManagerMock();
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = false;

        $manager->increase(1001, 30, ['operationId' => 'campaign:single']);

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1001, 30, ['operationId' => 'campaign:single']);
    }

    public function testRequireOperationIdRejectsMissingValue(): void
    {
        $manager = new ManagerMock();
        $manager->requireOperationId = true;

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1001, 30, []);
    }

    public function testRequireOperationIdErrorContainsAttributeName(): void
    {
        $manager = new ManagerMock();
        $manager->requireOperationId = true;
        $manager->operationIdAttribute = 'externalOperationId';

        try {
            $manager->increase(1001, 30, []);
            self::fail('Ожидалось исключение при отсутствии operationId.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('externalOperationId', $invalidArgumentException->getMessage());
        }
    }

    public function testRequireOperationIdRejectsInvalidValueType(): void
    {
        $manager = new ManagerMock();
        $manager->requireOperationId = true;

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1001, 30, ['operationId' => ['nested' => true]]);
    }

    public function testInvalidOperationIdErrorContainsAttributeName(): void
    {
        $manager = new ManagerMock();
        $manager->requireOperationId = true;
        $manager->operationIdAttribute = 'externalOperationId';

        try {
            $manager->increase(1001, 30, ['externalOperationId' => ['nested' => true]]);
            self::fail('Ожидалось исключение для некорректного типа operationId.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('externalOperationId', $invalidArgumentException->getMessage());
        }
    }

    public function testOperationIdContainingOnlySpacesIsRejected(): void
    {
        $manager = new ManagerMock();
        $manager->requireOperationId = true;

        $this->expectException('yii\base\InvalidArgumentException');
        $manager->increase(1001, 30, ['operationId' => '   ']);
    }

    public function testOperationIdContainingOnlySpacesErrorContainsCustomAttribute(): void
    {
        $manager = new ManagerMock();
        $manager->requireOperationId = true;
        $manager->operationIdAttribute = 'externalOperationId';

        try {
            $manager->increase(1001, 30, ['externalOperationId' => '   ']);
            self::fail('Ожидалось исключение для пустого operationId после trim.');
        } catch (\yii\base\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('externalOperationId', $invalidArgumentException->getMessage());
        }
    }

    public function testNormalizeAmountAcceptsFractionalNumericStringAsFloat(): void
    {
        $manager = new ManagerMock();
        $normalizedAmount = $this->invokeNonPublicMethod($manager, 'normalizeAmount', ['10.50']);

        self::assertIsFloat($normalizedAmount);
        self::assertSame(10.5, $normalizedAmount);
    }

    public function testAssertRequestedAmountAcceptsFiniteFloat(): void
    {
        $manager = new ManagerMock();
        $this->invokeNonPublicMethod($manager, 'assertRequestedAmountIsPositive', [10.5]);
        self::assertCount(0, $manager->transactions);
    }

    public function testAssertRequestedAmountRejectsInfiniteFloat(): void
    {
        $manager = new ManagerMock();

        $this->expectException(\yii\base\InvalidArgumentException::class);
        $this->invokeNonPublicMethod($manager, 'assertRequestedAmountIsPositive', [INF]);
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

    public function testBalanceRulesDefaultConstructorValues(): void
    {
        $rules = new BalanceRules();

        self::assertTrue($rules->requirePositiveAmount);
        self::assertTrue($rules->forbidTransferToSameAccount);
        self::assertFalse($rules->forbidNegativeBalance);
        self::assertSame(0, $rules->minimumAllowedBalance);
    }

    public function testBalanceRulesStrictDefaultMinimum(): void
    {
        $rules = BalanceRules::strict();

        self::assertTrue($rules->requirePositiveAmount);
        self::assertTrue($rules->forbidTransferToSameAccount);
        self::assertTrue($rules->forbidNegativeBalance);
        self::assertSame(0, $rules->minimumAllowedBalance);
    }

    public function testBalanceRulesStrictUsesProvidedMinimum(): void
    {
        $rules = BalanceRules::strict(-5);

        self::assertSame(-5, $rules->minimumAllowedBalance);
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
        $eventAccountId = null;
        $manager->on(Manager::EVENT_BEFORE_CREATE_TRANSACTION, function ($event) use (&$eventAccountId): void {
            /* @var $event TransactionEvent */
            $eventAccountId = $event->accountId;
            $event->transactionData['extra'] = 'event';
        });

        $manager->increase(1, 50);

        $transaction = $manager->getLastTransaction();
        self::assertEquals('event', $transaction['extra']);
        self::assertSame(1, $eventAccountId);
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

    public function testRevertRoutesZeroAmountToDecreaseForRegularTransaction(): void
    {
        $manager = new class () extends ManagerMock {
            public ?string $lastMethod = null;

            /**
             * @var array<string, mixed>|null
             */
            public ?array $forcedTransaction = null;

            public function increase(mixed $account, int|float $amount, array $data = []): mixed
            {
                $this->lastMethod = 'increase';

                return 'increase';
            }

            public function decrease(mixed $account, int|float $amount, array $data = []): mixed
            {
                $this->lastMethod = 'decrease';

                return 'decrease';
            }

            public function transfer(mixed $from, mixed $to, int|float $amount, array $data = []): array
            {
                $this->lastMethod = 'transfer';

                return ['transfer'];
            }

            protected function findTransaction(mixed $id): ?array
            {
                return $this->forcedTransaction;
            }
        };

        $manager->forcedTransaction = [
            $manager->accountLinkAttribute => 100,
            $manager->amountAttribute => 0,
        ];
        $manager->requirePositiveAmount = false;

        $manager->revert(1);

        self::assertSame('decrease', $manager->lastMethod);
    }

    public function testRevertRoutesZeroAmountTransferInExpectedDirection(): void
    {
        $manager = new class () extends ManagerMock {
            /**
             * @var array<int, mixed>|null
             */
            public ?array $lastTransfer = null;

            /**
             * @var array<string, mixed>|null
             */
            public ?array $forcedTransaction = null;

            public function transfer(mixed $from, mixed $to, int|float $amount, array $data = []): array
            {
                $this->lastTransfer = [$from, $to, $amount];

                return ['transfer'];
            }

            protected function findTransaction(mixed $id): ?array
            {
                return $this->forcedTransaction;
            }
        };

        $manager->extraAccountLinkAttribute = 'extraAccountId';
        $manager->forcedTransaction = [
            $manager->accountLinkAttribute => 100,
            $manager->extraAccountLinkAttribute => 200,
            $manager->amountAttribute => 0,
        ];
        $manager->requirePositiveAmount = false;

        $manager->revert(1);

        self::assertSame([100, 200, 0], $manager->lastTransfer);
    }

    public function testProtectedExtensionPointsAreActuallyOverridable(): void
    {
        $manager = new class () extends ManagerMock {
            /**
             * @var array<string, int>
             */
            public array $calls = [
                'createSignedTransaction' => 0,
                'getDateAttributeValue' => 0,
                'assertRequestedAmountIsPositive' => 0,
                'isSameAccount' => 0,
                'beforeCreateTransaction' => 0,
                'afterCreateTransaction' => 0,
                'assertOperationIdRules' => 0,
                'extractOperationId' => 0,
            ];

            protected function createSignedTransaction(mixed $account, int|float $signedAmount, array $data = []): mixed
            {
                $this->calls['createSignedTransaction']++;

                return parent::createSignedTransaction($account, $signedAmount, $data);
            }

            protected function getDateAttributeValue(): mixed
            {
                $this->calls['getDateAttributeValue']++;

                return parent::getDateAttributeValue();
            }

            protected function assertRequestedAmountIsPositive(int|float $amount): void
            {
                $this->calls['assertRequestedAmountIsPositive']++;
                parent::assertRequestedAmountIsPositive($amount);
            }

            protected function isSameAccount(mixed $firstAccountId, mixed $secondAccountId): bool
            {
                $this->calls['isSameAccount']++;

                return parent::isSameAccount($firstAccountId, $secondAccountId);
            }

            protected function beforeCreateTransaction(mixed $accountId, array $data): array
            {
                $this->calls['beforeCreateTransaction']++;

                return parent::beforeCreateTransaction($accountId, $data);
            }

            protected function afterCreateTransaction(mixed $transactionId, mixed $accountId, array $data): void
            {
                $this->calls['afterCreateTransaction']++;
                parent::afterCreateTransaction($transactionId, $accountId, $data);
            }

            protected function assertOperationIdRules(mixed $accountId, array $data): void
            {
                $this->calls['assertOperationIdRules']++;
                parent::assertOperationIdRules($accountId, $data);
            }

            protected function extractOperationId(array $data): ?string
            {
                $this->calls['extractOperationId']++;

                return parent::extractOperationId($data);
            }
        };

        $manager->forbidTransferToSameAccount = true;
        $manager->forbidDuplicateOperationId = true;
        $manager->requireOperationId = true;

        $manager->increase(1, 10, ['operationId' => 'ext:1']);
        try {
            $manager->transfer(1, 1, 10, ['operationId' => 'ext:2']);
            self::fail('Ожидалось исключение перевода на тот же счёт.');
        } catch (\yii\base\InvalidArgumentException) {
            // Ожидаемая ветка.
        }

        self::assertGreaterThan(0, $manager->calls['createSignedTransaction']);
        self::assertGreaterThan(0, $manager->calls['getDateAttributeValue']);
        self::assertGreaterThan(0, $manager->calls['assertRequestedAmountIsPositive']);
        self::assertGreaterThan(0, $manager->calls['isSameAccount']);
        self::assertGreaterThan(0, $manager->calls['beforeCreateTransaction']);
        self::assertGreaterThan(0, $manager->calls['afterCreateTransaction']);
        self::assertGreaterThan(0, $manager->calls['assertOperationIdRules']);
        self::assertGreaterThan(0, $manager->calls['extractOperationId']);
    }

    public function testTranslationMethodIsPublicAndLoadsMessages(): void
    {
        $originalLanguage = Yii::$app->language;
        unset(Yii::$app->getI18n()->translations[Manager::I18N_CATEGORY]);
        Yii::$app->language = 'en-US';
        try {
            $message = Manager::t('error.amount_not_numeric');
        } finally {
            Yii::$app->language = $originalLanguage;
        }

        self::assertSame('Сумма операции должна быть числом.', $message);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokeNonPublicMethod(object $object, string $method, array $arguments): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $arguments);
    }
}
