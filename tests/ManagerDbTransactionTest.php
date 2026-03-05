<?php

declare(strict_types=1);

namespace nazbav\tests\unit\balance;

use nazbav\balance\ManagerDbTransaction;
use yii\db\Transaction;

final class ManagerDbTransactionTestManager extends ManagerDbTransaction
{
    public int $beginCalls = 0;

    public int $commitCalls = 0;

    public int $rollbackCalls = 0;

    public bool $failCreateTransaction = false;

    public bool $failFindTransaction = false;

    public ?Transaction $transactionToCreate = null;

    /**
     * @var array<string, string>
     */
    private array $accounts = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $transactions = [];

    /**
     * @var array<int|string, int|float>
     */
    private array $balances = [];

    /**
     * @param array<string, mixed> $attributes
     */
    protected function findAccountId(array $attributes): mixed
    {
        $id = serialize($attributes);

        return $this->accounts[$id] ?? null;
    }

    protected function findTransaction(mixed $id): ?array
    {
        if ($this->failFindTransaction) {
            throw new \RuntimeException('forced-find');
        }

        return $this->transactions[(int) $id] ?? null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createAccount(array $attributes): mixed
    {
        $id = serialize($attributes);
        $this->accounts[$id] = $id;

        return $id;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createTransaction(array $attributes): mixed
    {
        if ($this->failCreateTransaction) {
            throw new \RuntimeException('forced-create');
        }

        $id = count($this->transactions);
        $attributes['id'] = $id;
        $this->transactions[$id] = $attributes;

        return $id;
    }

    protected function incrementAccountBalance(mixed $accountId, int|float $amount): void
    {
        if (!isset($this->balances[$accountId])) {
            $this->balances[$accountId] = 0;
        }

        $this->balances[$accountId] += $amount;
    }

    public function calculateBalance(mixed $account): int|float|null
    {
        $accountId = is_array($account) ? $this->findAccountId($account) : $account;
        if ($accountId === null || !isset($this->balances[$accountId])) {
            return null;
        }

        return $this->balances[$accountId];
    }

    protected function beginDbTransaction(): void
    {
        $this->beginCalls++;
        parent::beginDbTransaction();
    }

    protected function commitDbTransaction(): void
    {
        $this->commitCalls++;
        parent::commitDbTransaction();
    }

    protected function rollBackDbTransaction(): void
    {
        $this->rollbackCalls++;
        parent::rollBackDbTransaction();
    }

    protected function createDbTransaction(): ?Transaction
    {
        return $this->transactionToCreate;
    }
}

final class ManagerDbTransactionTest extends TestCase
{
    public function testIncreaseUsesBeginAndCommitHooks(): void
    {
        $manager = $this->createManager();

        $manager->increase(1, 10);

        self::assertSame(1, $manager->beginCalls);
        self::assertSame(1, $manager->commitCalls);
        self::assertSame(0, $manager->rollbackCalls);
    }

    public function testIncreaseRollsBackOnFailure(): void
    {
        $manager = $this->createManager();
        $manager->failCreateTransaction = true;

        try {
            $manager->increase(1, 10);
            self::fail('Ожидалось исключение при ошибке создания транзакции.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('forced-create', $runtimeException->getMessage());
        }

        self::assertSame(1, $manager->beginCalls);
        self::assertSame(0, $manager->commitCalls);
        self::assertSame(1, $manager->rollbackCalls);
    }

    public function testDecreaseUsesCommitHookOnSuccess(): void
    {
        $manager = $this->createManager();

        $manager->decrease(1, 10);

        self::assertSame(1, $manager->beginCalls);
        self::assertSame(1, $manager->commitCalls);
        self::assertSame(0, $manager->rollbackCalls);
    }

    public function testTransferUsesHooksAndReturnsTwoIds(): void
    {
        $manager = $this->createManager();

        $result = $manager->transfer(1, 2, 10);

        self::assertCount(2, $result);
        self::assertSame(3, $manager->beginCalls);
        self::assertSame(3, $manager->commitCalls);
        self::assertSame(0, $manager->rollbackCalls);
    }

    public function testTransferRollsBackOnFailure(): void
    {
        $manager = $this->createManager();

        try {
            $manager->transfer(1, 1, 10);
            self::fail('Ожидалось исключение при переводе на тот же счёт.');
        } catch (\yii\base\InvalidArgumentException) {
            // Ожидаемая ветка.
        }

        self::assertSame(1, $manager->beginCalls);
        self::assertSame(0, $manager->commitCalls);
        self::assertSame(1, $manager->rollbackCalls);
    }

    public function testRevertUsesBeginAndCommitHooks(): void
    {
        $manager = $this->createManager();
        $transactionId = $manager->increase(1, 10);
        $beginBefore = $manager->beginCalls;
        $commitBefore = $manager->commitCalls;
        $rollbackBefore = $manager->rollbackCalls;

        $manager->revert($transactionId);

        self::assertSame($beginBefore + 2, $manager->beginCalls);
        self::assertSame($commitBefore + 2, $manager->commitCalls);
        self::assertSame($rollbackBefore, $manager->rollbackCalls);
    }

    public function testRevertRollsBackOnFailure(): void
    {
        $manager = $this->createManager();
        $manager->failFindTransaction = true;

        try {
            $manager->revert(1);
            self::fail('Ожидалось исключение при ошибке поиска транзакции.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('forced-find', $runtimeException->getMessage());
        }

        self::assertSame(1, $manager->beginCalls);
        self::assertSame(0, $manager->commitCalls);
        self::assertSame(1, $manager->rollbackCalls);
    }

    public function testRollbackDoesNotCrashWhenDbTransactionIsNull(): void
    {
        $manager = $this->createManager();
        $manager->transactionToCreate = null;
        $manager->failCreateTransaction = true;

        try {
            $manager->increase(1, 10);
            self::fail('Ожидалось исключение при ошибке создания транзакции.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('forced-create', $runtimeException->getMessage());
        }

        self::assertSame(1, $manager->rollbackCalls);
    }

    private function createManager(): ManagerDbTransactionTestManager
    {
        return new ManagerDbTransactionTestManager();
    }
}
