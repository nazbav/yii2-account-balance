<?php

declare(strict_types=1);

namespace nazbav\balance;

use Throwable;
use yii\db\Exception;
use yii\db\Transaction;

/**
 * ManagerDbTransaction выполняет операции баланса в рамках единой транзакции базы данных.
 *
 * @see Manager
 *
 * @since 1.0
 */
abstract class ManagerDbTransaction extends Manager
{
    /**
     * @var array<int, Transaction|null> стек внутренних экземпляров транзакций.
     */
    private array $dbTransactions = [];

    /**
     * @param array<string, mixed> $data
     * @throws Exception
     * @throws Throwable
     */
    public function increase(mixed $account, int|float $amount, array $data = []): mixed
    {
        $this->beginDbTransaction();
        try {
            $result = parent::increase($account, $amount, $data);
            $this->commitDbTransaction();

            return $result;
        } catch (Throwable $throwable) {
            $this->rollBackDbTransaction();
            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, mixed>
     * @throws Exception
     * @throws Throwable
     */
    public function transfer(mixed $from, mixed $to, int|float $amount, array $data = []): array
    {
        $this->beginDbTransaction();
        try {
            $result = parent::transfer($from, $to, $amount, $data);
            $this->commitDbTransaction();

            return $result;
        } catch (Throwable $throwable) {
            $this->rollBackDbTransaction();
            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @throws Exception
     * @throws Throwable
     */
    public function revert(mixed $transactionId, array $data = []): mixed
    {
        $this->beginDbTransaction();
        try {
            $result = parent::revert($transactionId, $data);
            $this->commitDbTransaction();

            return $result;
        } catch (Throwable $throwable) {
            $this->rollBackDbTransaction();
            throw $throwable;
        }
    }

    protected function beginDbTransaction(): void
    {
        $this->dbTransactions[] = $this->createDbTransaction();
    }

    /**
     * @throws Exception
     */
    protected function commitDbTransaction(): void
    {
        $transaction = array_pop($this->dbTransactions);
        $transaction?->commit();
    }

    protected function rollBackDbTransaction(): void
    {
        $transaction = array_pop($this->dbTransactions);
        $transaction?->rollBack();
    }

    /**
     * Создаёт экземпляр транзакции и фактически начинает её.
     * Если транзакции не поддерживаются, возвращает `null`.
     */
    abstract protected function createDbTransaction(): ?Transaction;
}
