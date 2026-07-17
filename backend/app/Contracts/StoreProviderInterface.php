<?php

namespace App\Contracts;

use App\Data\ProviderResult;
use App\Models\Chain;

interface StoreProviderInterface
{
    public function code(): string;

    public function parse(Chain $chain, ?int $cityId = null, ?int $storeId = null): ProviderResult;
}
