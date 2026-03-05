<?php

declare(strict_types=1);


namespace yii2tech\tests\unit\balance\data;

use yii2tech\balance\ManagerDataSerializeTrait;

/**
 * ManagerDataSerialize is a mock manager class for the [[ManagerDataSerializeTrait]] testing.
 */
class ManagerDataSerialize extends ManagerMock
{
    use ManagerDataSerializeTrait;

    /**
     * {@inheritdoc}
     */
    protected function createTransaction(array $attributes): mixed
    {
        static $allowedAttributes = [
            'date',
            'accountId',
            'amount',
        ];
        $attributes = $this->serializeAttributes($attributes, $allowedAttributes);

        return parent::createTransaction($attributes);
    }

    /**
     * {@inheritdoc}
     */
    protected function findTransaction(mixed $id): ?array
    {
        $transaction = parent::findTransaction($id);
        if ($transaction === null) {
            return $transaction;
        }

        return $this->unserializeAttributes($transaction);
    }
}
