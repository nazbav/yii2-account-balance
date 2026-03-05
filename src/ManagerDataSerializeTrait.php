<?php

declare(strict_types=1);

/**
 * @link https://github.com/yii2tech
 * @copyright Copyright (c) 2015 Yii2tech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace yii2tech\balance;

use yii\base\InvalidArgumentException;
use yii\di\Instance;

/**
 * ManagerDataSerializeTrait provides ability to serialize extra attributes into the single field.
 * It may be useful using data storage with static data schema, like relational database.
 * This trait supposed to be used inside descendant of [[Manager]].
 *
 * @see Manager
 * @see SerializerInterface
 *
 * @property string|array|SerializerInterface $serializer serializer instance or its configuration.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
trait ManagerDataSerializeTrait
{
    /**
     * @var string|null name of the transaction entity attribute for serialized data.
     */
    public ?string $dataAttribute = 'data';

    /**
     * @var string|array<string, mixed>|SerializerInterface serializer instance or its configuration.
     */
    private string|array|SerializerInterface $_serializer = 'json';

    public function getSerializer(): SerializerInterface
    {
        if (!$this->_serializer instanceof SerializerInterface) {
            $this->_serializer = $this->createSerializer($this->_serializer);
        }

        return $this->_serializer;
    }

    /**
     * @param SerializerInterface|array<string, mixed>|string $serializer
     */
    public function setSerializer(SerializerInterface|array|string $serializer): void
    {
        $this->_serializer = $serializer;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string> $allowedAttributes
     * @return array<string, mixed>
     */
    protected function serializeAttributes(array $attributes, array $allowedAttributes): array
    {
        if ($this->dataAttribute === null) {
            return $attributes;
        }

        $safeAttributes = [];
        $dataAttributes = [];
        foreach ($attributes as $name => $value) {
            if (in_array($name, $allowedAttributes, true)) {
                $safeAttributes[$name] = $value;
            } else {
                $dataAttributes[$name] = $value;
            }
        }
        if ($dataAttributes !== []) {
            $safeAttributes[$this->dataAttribute] = $this->getSerializer()->serialize($dataAttributes);
        }

        return $safeAttributes;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function unserializeAttributes(array $attributes): array
    {
        if ($this->dataAttribute === null) {
            return $attributes;
        }

        if (empty($attributes[$this->dataAttribute])) {
            unset($attributes[$this->dataAttribute]);

            return $attributes;
        }

        $rawValue = (string) $attributes[$this->dataAttribute];
        $dataAttributes = $this->getSerializer()->unserialize($rawValue);
        if (!is_array($dataAttributes)) {
            throw new InvalidArgumentException(Manager::t('error.serialized_data_must_be_array'));
        }
        unset($attributes[$this->dataAttribute]);

        return array_merge($attributes, $dataAttributes);
    }

    /**
     * @param string|array<string, mixed> $config
     */
    protected function createSerializer(string|array $config): SerializerInterface
    {
        if (is_string($config)) {
            $config = match ($config) {
                'php' => ['class' => PhpSerializer::class],
                'json' => ['class' => JsonSerializer::class],
                default => ['class' => $config],
            };
        } elseif (!isset($config['class'])) {
            $config['class'] = CallbackSerializer::class;
        }

        return Instance::ensure($config, SerializerInterface::class);
    }
}
