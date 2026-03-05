<?php

declare(strict_types=1);

namespace nazbav\balance;

use yii\base\BaseObject;

/**
 * PhpSerializer использует встроенные функции PHP `serialize()` и `unserialize()`.
 *
 * @since 1.0
 */
class PhpSerializer extends BaseObject implements SerializerInterface
{
    /**
     * @var bool|array<int, class-string> управляет созданием классов при `unserialize()`.
     * По умолчанию установлено `false`, чтобы исключить внедрение объектов из недоверенных данных.
     */
    public bool|array $allowedClasses = false;

    public function serialize(mixed $value): string
    {
        return serialize($value);
    }

    public function unserialize(string $value): mixed
    {
        return unserialize($value, ['allowed_classes' => $this->allowedClasses]);
    }
}
