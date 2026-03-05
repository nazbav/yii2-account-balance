<?php

declare(strict_types=1);


namespace yii2tech\tests\unit\balance\data;

/**
 * Тестовый класс-имитатор менеджера.
 */
class ManagerMock extends \yii2tech\balance\Manager
{
    /**
     * @var array<string, string> список счётов.
     */
    public $accounts = [];
    /**
     * @var array<int|string, int|float> текущие балансы счётов.
     */
    public $accountBalances = [];
    /**
     * @var array<int, array<string, mixed>> список выполненных транзакций.
     */
    public $transactions = [];


    /**
     * @return array данные последней транзакции.
     */
    public function getLastTransaction()
    {
        $transaction = end($this->transactions);
        if ($transaction === false) {
            return [];
        }
        return $transaction;
    }

    /**
     * @return array[] данные двух последних транзакций.
     */
    public function getLastTransactionPair()
    {
        $last = end($this->transactions);
        $preLast = prev($this->transactions);
        if ($preLast === false) {
            $preLast = [];
        }
        if ($last === false) {
            $last = [];
        }
        return [$preLast, $last];
    }

    /**
     * {@inheritdoc}
     */
    public function calculateBalance(mixed $account): int|float|null
    {
        $accountId = is_array($account) ? $this->findAccountId($account) : $account;
        if ($accountId === null) {
            return null;
        }
        return $this->accountBalances[$accountId];
    }

    /**
     * {@inheritdoc}
     */
    protected function createTransaction(array $attributes): mixed
    {
        $transactionId = count($this->transactions);
        $attributes['id'] = $transactionId;
        $this->transactions[] = $attributes;
        return $transactionId;
    }

    /**
     * {@inheritdoc}
     */
    protected function findAccountId(array $attributes): mixed
    {
        $id = serialize($attributes);
        if (isset($this->accounts[$id])) {
            return $id;
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    protected function createAccount(array $attributes): mixed
    {
        $id = serialize($attributes);
        $this->accounts[$id] = $id;
        return $id;
    }

    /**
     * {@inheritdoc}
     */
    protected function incrementAccountBalance(mixed $accountId, int|float $amount): void
    {
        if (!isset($this->accountBalances[$accountId])) {
            $this->accountBalances[$accountId] = 0;
        }
        $this->accountBalances[$accountId] += $amount;
    }

    /**
     * {@inheritdoc}
     */
    protected function findTransaction(mixed $id): ?array
    {
        if (isset($this->transactions[$id])) {
            return $this->transactions[$id];
        }
        return null;
    }
}
