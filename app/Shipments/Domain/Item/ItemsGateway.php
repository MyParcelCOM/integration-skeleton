<?php

declare(strict_types=1);

namespace App\Shipments\Domain\Item;

use App\Http\ExactApiClient;
use App\Shipments\Domain\MakeRequest;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;
use ODataQuery\ODataResourcePath;
use Ramsey\Uuid\UuidInterface;
use function array_key_exists;

class ItemsGateway
{
    use MakeRequest;

    private const ENTITY = 'logistics/Items';

    /**
     * In-memory cache for already requested items
     *
     * @var Item[]
     */
    private array $items = [];

    public function __construct(
        private ExactApiClient $client,
        private ItemFactory $itemFactory
    ) {
    }

    public function fetchOneByItemId(UuidInterface $id): Item
    {
        if (!array_key_exists($id->toString(), $this->items)) {
            try {
                $response = $this->request(new ODataResourcePath(self::ENTITY . "(guid'${id}')"));
            } catch (GuzzleException $e) {
                return $this->itemFactory->createNullItem();
            }
            $this->items[$id->toString()] = $this->itemFactory->createFromArray(
                (array) Arr::get($response, 'd', [])
            );
        }

        return $this->items[$id->toString()];
    }
}