<?php

declare(strict_types=1);

namespace yii2tech\balance;

use yii\base\BaseObject;

/**
 * CallbackSerializer сериализует данные через пользовательские PHP-колбэки.
 *
 * @since 1.0
 */
class CallbackSerializer extends BaseObject implements SerializerInterface
{
    /**
     * @var callable(mixed):string колбэк для сериализации значения.
     */
    public $serialize;

    /**
     * @var callable(string):mixed колбэк для восстановления значения.
     */
    public $unserialize;

    public function serialize(mixed $value): string
    {
        return call_user_func($this->serialize, $value);
    }

    public function unserialize(string $value): mixed
    {
        return call_user_func($this->unserialize, $value);
    }
}
