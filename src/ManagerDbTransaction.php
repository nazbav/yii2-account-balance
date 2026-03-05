<?php

declare(strict_types=1);

/**
 * @link https://github.com/yii2tech
 * @copyright Copyright (c) 2015 Yii2tech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace yii2tech\balance;

use yii\db\Transaction;

/**
 * ManagerDbTransaction allows performing balance operations as a single Database transaction.
 *
 * @see Manager
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
abstract class ManagerDbTransaction extends Manager
{
    /**
     * @var array<int, Transaction|null> internal transaction instances stack.
     */
    private array $dbTransactions = [];

    /**
     * @param mixed $account
     * @param int|float $amount
     * @param array<string, mixed> $data
     */
    public function increase(mixed $account, int|float $amount, array $data = []): mixed
    {
        $this->beginDbTransaction();
        try {
            $result = parent::increase($account, $amount, $data);
            $this->commitDbTransaction();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBackDbTransaction();
            throw $e;
        }
    }

    /**
     * @param mixed $from
     * @param mixed $to
     * @param int|float $amount
     * @param array<string, mixed> $data
     * @return array<int, mixed>
     */
    public function transfer(mixed $from, mixed $to, int|float $amount, array $data = []): array
    {
        $this->beginDbTransaction();
        try {
            $result = parent::transfer($from, $to, $amount, $data);
            $this->commitDbTransaction();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBackDbTransaction();
            throw $e;
        }
    }

    /**
     * @param mixed $transactionId
     * @param array<string, mixed> $data
     */
    public function revert(mixed $transactionId, array $data = []): mixed
    {
        $this->beginDbTransaction();
        try {
            $result = parent::revert($transactionId, $data);
            $this->commitDbTransaction();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBackDbTransaction();
            throw $e;
        }
    }

    protected function beginDbTransaction(): void
    {
        $this->dbTransactions[] = $this->createDbTransaction();
    }

    protected function commitDbTransaction(): void
    {
        $transaction = array_pop($this->dbTransactions);
        if ($transaction !== null) {
            $transaction->commit();
        }
    }

    protected function rollBackDbTransaction(): void
    {
        $transaction = array_pop($this->dbTransactions);
        if ($transaction !== null) {
            $transaction->rollBack();
        }
    }

    /**
     * Creates transaction instance, actually beginning transaction.
     * If transactions are not supported, `null` will be returned.
     */
    abstract protected function createDbTransaction(): ?Transaction;
}
