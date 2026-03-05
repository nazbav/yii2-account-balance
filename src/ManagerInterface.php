<?php

declare(strict_types=1);

/**
 * @link https://github.com/yii2tech
 * @copyright Copyright (c) 2015 Yii2tech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace yii2tech\balance;

/**
 * ManagerInterface defines balance manager interface.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
interface ManagerInterface
{
    /**
     * Increases account current balance ('debit' operation).
     *
     * @param mixed $account account ID or filter condition.
     * @param int|float $amount amount.
     * @param array<string, mixed> $data extra data associated with the transaction.
     */
    public function increase(mixed $account, int|float $amount, array $data = []): mixed;

    /**
     * Decreases account current balance ('credit' operation).
     *
     * @param mixed $account account ID or filter condition.
     * @param int|float $amount amount.
     * @param array<string, mixed> $data extra data associated with the transaction.
     */
    public function decrease(mixed $account, int|float $amount, array $data = []): mixed;

    /**
     * Transfers amount from one account to the other one.
     *
     * @param mixed $from account ID or filter condition.
     * @param mixed $to account ID or filter condition.
     * @param int|float $amount amount.
     * @param array<string, mixed> $data extra data associated with the transaction.
     * @return array<int, mixed> list of created transaction IDs.
     */
    public function transfer(mixed $from, mixed $to, int|float $amount, array $data = []): array;

    /**
     * Reverts specified transaction.
     *
     * @param mixed $transactionId ID of the transaction to be reverted.
     * @param array<string, mixed> $data extra transaction data.
     */
    public function revert(mixed $transactionId, array $data = []): mixed;

    /**
     * Calculates current account balance summarizing all related transactions.
     *
     * @param mixed $account account ID or filter condition.
     */
    public function calculateBalance(mixed $account): int|float|null;
}
