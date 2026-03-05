<?php

declare(strict_types=1);

/**
 * @link https://github.com/yii2tech
 * @copyright Copyright (c) 2015 Yii2tech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace yii2tech\balance;

use yii\base\BaseObject;
use yii\helpers\Json;

/**
 * JsonSerializer serializes data in JSON format.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
class JsonSerializer extends BaseObject implements SerializerInterface
{
    /**
     * @var int the encoding options.
     */
    public int $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function serialize(mixed $value): string
    {
        return Json::encode($value, $this->options);
    }

    public function unserialize(string $value): mixed
    {
        return Json::decode($value);
    }
}
