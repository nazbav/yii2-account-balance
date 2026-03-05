<?php

declare(strict_types=1);

namespace nazbav\tests\unit\balance;

use nazbav\tests\unit\balance\data\ManagerDataSerialize;

class ManagerDataSerializeTraitTest extends TestCase
{
    /**
     * @return array<int, array{0: string|array<string, mixed>}>
     */
    public function dataProviderSerializeMethod(): array
    {
        return [
            ['json'],
            ['php'],
            [
                [
                    'serialize' => fn ($value): string => serialize($value),
                    'unserialize' => fn ($value): mixed => unserialize($value),
                ],
            ],
            [
                [
                    'class' => 'nazbav\balance\PhpSerializer',
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataProviderSerializeMethod
     *
     * @param string|array<string, mixed> $serializer
     */
    public function testSerialize(string|array $serializer): void
    {
        $manager = new ManagerDataSerialize();
        $manager->serializer = $serializer;

        $manager->increase(1, 50, ['extra' => 'custom']);
        $transaction = $manager->getLastTransaction();
        self::assertEquals(50, $transaction['amount']);
        self::assertStringContainsString('custom', $transaction['data']);
    }

    /**
     * @depends testSerialize
     * @dataProvider dataProviderSerializeMethod
     *
     * @param string|array<string, mixed> $serializer
     */
    public function testUnserialize(string|array $serializer): void
    {
        $manager = new ManagerDataSerialize();
        $manager->serializer = $serializer;
        $manager->extraAccountLinkAttribute = 'extraAccountId';

        $fromId = 10;
        $toId = 20;
        $transactionIds = $manager->transfer($fromId, $toId, 10);
        $manager->revert($transactionIds[0]);

        self::assertCount(4, $manager->transactions);
    }

    public function testPhpSerializerBlocksObjectInstantiation(): void
    {
        $serializer = new \nazbav\balance\PhpSerializer();
        $payload = $serializer->serialize(['item' => new \stdClass()]);

        $decoded = $serializer->unserialize($payload);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('item', $decoded);
        self::assertNotInstanceOf(\stdClass::class, $decoded['item']);
    }

    public function testPhpSerializerAllowsConfiguredClasses(): void
    {
        $serializer = new \nazbav\balance\PhpSerializer([
            'allowedClasses' => [\stdClass::class],
        ]);
        $payload = $serializer->serialize(['item' => new \stdClass()]);

        $decoded = $serializer->unserialize($payload);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('item', $decoded);
        self::assertInstanceOf(\stdClass::class, $decoded['item']);
    }

    public function testSerializerAccessorMethodsArePublicAndWork(): void
    {
        $manager = new ManagerDataSerialize();

        $manager->setSerializer('json');

        $serializer = $manager->getSerializer();

        self::assertInstanceOf(\nazbav\balance\JsonSerializer::class, $serializer);
    }

    public function testSerializerCanBeConfiguredByClassStringName(): void
    {
        $manager = new ManagerDataSerialize();
        $manager->setSerializer(\nazbav\balance\JsonSerializer::class);

        $manager->increase(1, 10, ['extra' => 'by-class-string']);

        $transaction = $manager->getLastTransaction();

        self::assertStringContainsString('by-class-string', $transaction['data']);
    }

    public function testUnserializeCastsRawDataToStringBeforeSerializerCall(): void
    {
        $manager = new class () extends ManagerDataSerialize {
            /**
             * @param array<string, mixed> $attributes
             * @return array<string, mixed>
             */
            public function unserializePublic(array $attributes): array
            {
                return $this->unserializeAttributes($attributes);
            }
        };

        $manager->setSerializer([
            'serialize' => static fn ($value): string => (string) $value,
            'unserialize' => static fn (string $value): array => ['decoded' => $value],
        ]);

        $decoded = $manager->unserializePublic(['data' => 123]);

        self::assertSame('123', $decoded['decoded']);
    }

    public function testTraitProtectedExtensionPointsAreOverridable(): void
    {
        $manager = new class () extends ManagerDataSerialize {
            public int $serializeCalls = 0;

            public int $unserializeCalls = 0;

            public int $createSerializerCalls = 0;

            /**
             * @return array<string, mixed>|null
             */
            public function findTransactionPublic(mixed $id): ?array
            {
                return $this->findTransaction($id);
            }

            /**
             * @param array<string, mixed> $attributes
             * @param array<int, string> $allowedAttributes
             * @return array<string, mixed>
             */
            protected function serializeAttributes(array $attributes, array $allowedAttributes): array
            {
                $this->serializeCalls++;

                return parent::serializeAttributes($attributes, $allowedAttributes);
            }

            /**
             * @param array<string, mixed> $attributes
             * @return array<string, mixed>
             */
            protected function unserializeAttributes(array $attributes): array
            {
                $this->unserializeCalls++;

                return parent::unserializeAttributes($attributes);
            }

            /**
             * @param string|array<string, mixed> $config
             */
            protected function createSerializer(string|array $config): \nazbav\balance\SerializerInterface
            {
                $this->createSerializerCalls++;

                return parent::createSerializer($config);
            }
        };

        $manager->setSerializer('json');

        $transactionId = $manager->increase(1, 10, ['extra' => 'ext-point']);
        $manager->findTransactionPublic($transactionId);

        self::assertGreaterThan(0, $manager->serializeCalls);
        self::assertGreaterThan(0, $manager->unserializeCalls);
        self::assertGreaterThan(0, $manager->createSerializerCalls);
    }
}
