<?php

declare(strict_types=1);

namespace nazbav\tests\unit\balance;

use nazbav\tests\unit\balance\data\ManagerDataSerialize;

class ManagerDataSerializeTraitTest extends TestCase
{
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
     */
    public function testSerialize(string|array $serializer): void
    {
        $manager = new ManagerDataSerialize();
        $manager->serializer = $serializer;

        $manager->increase(1, 50, ['extra' => 'custom']);
        $transaction = $manager->getLastTransaction();
        $this->assertEquals(50, $transaction['amount']);
        $this->assertStringContainsString('custom', $transaction['data']);
    }

    /**
     * @depends testSerialize
     * @dataProvider dataProviderSerializeMethod
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

        $this->assertCount(4, $manager->transactions);
    }

    public function testPhpSerializerBlocksObjectInstantiation(): void
    {
        $serializer = new \nazbav\balance\PhpSerializer();
        $payload = $serializer->serialize(['item' => new \stdClass()]);

        $decoded = $serializer->unserialize($payload);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('item', $decoded);
        $this->assertNotInstanceOf(\stdClass::class, $decoded['item']);
    }

    public function testPhpSerializerAllowsConfiguredClasses(): void
    {
        $serializer = new \nazbav\balance\PhpSerializer([
            'allowedClasses' => [\stdClass::class],
        ]);
        $payload = $serializer->serialize(['item' => new \stdClass()]);

        $decoded = $serializer->unserialize($payload);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('item', $decoded);
        $this->assertInstanceOf(\stdClass::class, $decoded['item']);
    }
}
