<?php

declare(strict_types=1);

/**
 * @link https://github.com/yii2tech
 * @copyright Copyright (c) 2015 Yii2tech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace yii2tech\balance;

use yii\base\BaseObject;

/**
 * PhpSerializer uses native PHP `serialize()` and `unserialize()` functions for the serialization.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
class PhpSerializer extends BaseObject implements SerializerInterface
{
    /**
     * @var bool|array<int, class-string> controls class instantiation at `unserialize()`.
     * Set to `false` by default to avoid object injection from untrusted payloads.
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
