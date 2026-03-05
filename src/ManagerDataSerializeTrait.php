<?php

declare(strict_types=1);

namespace nazbav\balance;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\di\Instance;

/**
 * ManagerDataSerializeTrait предоставляет возможность сериализовать дополнительные атрибуты в одно поле.
 * Подходит для хранилищ со статической схемой данных, например для реляционной базы данных.
 * Трейт предназначен для использования в наследниках [[Manager]].
 *
 * @see Manager
 * @see SerializerInterface
 *
 * @property string|array|SerializerInterface $serializer экземпляр сериализатора или его конфигурация.
 *
 * @since 1.0
 */
trait ManagerDataSerializeTrait
{
    /**
     * @var string|null имя атрибута сущности транзакции для сериализованных данных.
     */
    public ?string $dataAttribute = 'data';

    /**
     * @var string|array<string, mixed>|SerializerInterface экземпляр сериализатора или его конфигурация.
     */
    private string|array|SerializerInterface $_serializer = 'json';

    /**
     * @throws InvalidConfigException
     */
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
     * @throws InvalidConfigException
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
     * @throws InvalidConfigException
     */
    protected function unserializeAttributes(array $attributes): array
    {
        if ($this->dataAttribute === null) {
            return $attributes;
        }

        if (
            !array_key_exists($this->dataAttribute, $attributes)
            || $attributes[$this->dataAttribute] === null
            || $attributes[$this->dataAttribute] === ''
        ) {
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
     * @throws InvalidConfigException
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
