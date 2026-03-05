<?php

namespace yii2tech\tests\unit\balance\data;

/**
 * Manager mock class for the unit test
 */
class ManagerMock extends \yii2tech\balance\Manager
{
    /**
     * @var array<string, string> list of accounts
     */
    public $accounts = [];
    /**
     * @var array<int|string, int|float> account current balances.
     */
    public $accountBalances = [];
    /**
     * @var array<int, array<string, mixed>> list of performed transactions
     */
    public $transactions = [];


    /**
     * @return array last transaction data.
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
     * @return array[] last 2 transactions data.
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
    public function calculateBalance($account)
    {
        $accountId = $this->findAccountId($account);
        return $this->accountBalances[$accountId];
    }

    /**
     * {@inheritdoc}
     */
    protected function createTransaction($attributes)
    {
        $transactionId = count($this->transactions);
        $attributes['id'] = $transactionId;
        $this->transactions[] = $attributes;
        return $transactionId;
    }

    /**
     * {@inheritdoc}
     */
    protected function findAccountId($attributes)
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
    protected function createAccount($attributes)
    {
        $id = serialize($attributes);
        $this->accounts[$id] = $id;
        return $id;
    }

    /**
     * {@inheritdoc}
     */
    protected function incrementAccountBalance($accountId, $amount)
    {
        if (!isset($this->accountBalances[$accountId])) {
            $this->accountBalances[$accountId] = 0;
        }
        $this->accountBalances[$accountId] += $amount;
    }

    /**
     * {@inheritdoc}
     */
    protected function findTransaction($id)
    {
        if (isset($this->transactions[$id])) {
            return $this->transactions[$id];
        }
        return null;
    }
}
