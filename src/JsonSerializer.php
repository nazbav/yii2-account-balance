<?php

declare(strict_types=1);

namespace yii2tech\balance;

use yii\base\BaseObject;
use yii\helpers\Json;

/**
 * JsonSerializer сериализует данные в формате JSON.
 *
 * @since 1.0
 */
class JsonSerializer extends BaseObject implements SerializerInterface
{
    /**
     * @var int параметры кодирования.
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
