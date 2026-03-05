<?php

declare(strict_types=1);


namespace nazbav\tests\unit\balance;

use nazbav\tests\unit\balance\data\ManagerDataSerialize;

class ManagerDataSerializeTraitTest extends TestCase
{
    /**
     * @return array
     */
    public function dataProviderSerializeMethod()
    {
        return [
            ['json'],
            ['php'],
            [
                [
                    'serialize' => function ($value) {
                        return serialize($value);
                    },
                    'unserialize' => function ($value) {
                        return unserialize($value);
                    },
                ]
            ],
            [
                [
                    'class' => 'nazbav\balance\PhpSerializer'
                ]
            ],
        ];
    }

    /**
     * @dataProvider dataProviderSerializeMethod
     *
     * @param string|array $serializer
     */
    public function testSerialize($serializer)
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
     *
     * @param string|array $serializer
     */
    public function testUnserialize($serializer)
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
