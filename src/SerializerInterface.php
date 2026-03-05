<?php

declare(strict_types=1);

namespace yii2tech\balance;

/**
 * SerializerInterface определяет контракт сериализатора.
 *
 * @see ManagerDataSerializeTrait
 *
 * @since 1.0
 */
interface SerializerInterface
{
    /**
     * Сериализует переданное значение.
     */
    public function serialize(mixed $value): string;

    /**
     * Восстанавливает значение из сериализованного представления.
     */
    public function unserialize(string $value): mixed;
}
