<?php

namespace App\Services\StoreProviders;

use App\Contracts\StoreProviderInterface;
use InvalidArgumentException;

class StoreProviderManager
{
    /** @param iterable<StoreProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    public function forCode(string $code): StoreProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->code() === $code) {
                return $provider;
            }
        }

        throw new InvalidArgumentException("Provider [{$code}] is not registered.");
    }
}
