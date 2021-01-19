<?php

declare(strict_types=1);

namespace Tests\Unit\Shipments\Domain\Item;

use App\Shipments\Domain\Item\ItemFactory;
use App\Shipments\Domain\Item\NullWeight;
use Faker\Factory;
use PHPUnit\Framework\TestCase;
use function array_rand;
use function random_int;

class ItemFactoryTest extends TestCase
{
    public function test_should_create_item_from_array(): void
    {
        $this->expectNotToPerformAssertions();

        $faker = Factory::create();

        $factory = new ItemFactory();

        $description = $faker->text;
        $netWeight = random_int(10, 99);
        $units = ['kg', 'g'];
        $netWeightUnit = $units[array_rand($units)];
        $pictureUrl = $faker->imageUrl();

        $factory->createFromArray([
            'Description'   => $description,
            'NetWeight'     => $netWeight,
            'NetWeightUnit' => $netWeightUnit,
            'PictureUrl'    => $pictureUrl,
        ]);
    }

    public function test_should_create_item_from_array_with_nulls(): void
    {
        $factory = new ItemFactory();

        $item = $factory->createFromArray([
            'Description'   => null,
            'NetWeight'     => null,
            'NetWeightUnit' => null,
            'PictureUrl'    => null,
        ]);

        self::assertInstanceOf(NullWeight::class, $item->getWeight());
    }
}