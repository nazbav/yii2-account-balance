<?php

declare(strict_types=1);

namespace nazbav\balance;

use yii\base\Event;

/**
 * TransactionEvent описывает параметры события, связанного с транзакцией баланса.
 *
 * @since 1.0
 */
class TransactionEvent extends Event
{
    /**
     * @var mixed ID счёта, связанного с транзакцией.
     */
    public mixed $accountId = null;

    /**
     * @var array<string, mixed> данные транзакции.
     */
    public array $transactionData = [];

    /**
     * @var mixed ID транзакции.
     */
    public mixed $transactionId = null;
}
