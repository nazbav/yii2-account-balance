<?php

declare(strict_types=1);

/**
 * @link https://github.com/yii2tech
 * @copyright Copyright (c) 2015 Yii2tech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace yii2tech\balance;

/**
 * SerializerInterface defines serializer interface.
 *
 * @see ManagerDataSerializeTrait
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
interface SerializerInterface
{
    /**
     * Serializes given value.
     */
    public function serialize(mixed $value): string;

    /**
     * Restores value from its serialized representations.
     */
    public function unserialize(string $value): mixed;
}
