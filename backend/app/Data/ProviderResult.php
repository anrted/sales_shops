<?php

namespace App\Data;

class ProviderResult
{
    public function __construct(
        public readonly int $storesCount = 0,
        public readonly int $productsCount = 0,
        public readonly int $offersCount = 0,
    ) {
    }
}
