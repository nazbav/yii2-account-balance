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
 * CallbackSerializer serializes data via custom PHP callback.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
class CallbackSerializer extends BaseObject implements SerializerInterface
{
    /**
     * @var callable(mixed):string callback used for value serialization.
     */
    public $serialize;

    /**
     * @var callable(string):mixed callback used for value restoration.
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
