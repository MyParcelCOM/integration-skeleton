<?php

declare(strict_types=1);

namespace App\Shipments\Domain\Item;

class NullItem extends Item
{
    public function __construct()
    {
        parent::__construct('', new NullWeight(), '');
    }
}