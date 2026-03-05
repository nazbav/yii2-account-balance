<?php

declare(strict_types=1);


namespace nazbav\tests\unit\balance\data;

use nazbav\balance\ManagerDataSerializeTrait;

/**
 * ManagerDataSerialize — тестовый класс менеджера для проверки [[ManagerDataSerializeTrait]].
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
