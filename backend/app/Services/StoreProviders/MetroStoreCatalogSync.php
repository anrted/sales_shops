<?php

namespace App\Services\StoreProviders;

use App\Models\Chain;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetroStoreCatalogSync
{
    public function __construct(private readonly ProviderPersister $persister)
    {
    }

    /** @return array{cities:int, stores:int} */
    public function sync(): array
    {
        $chain = Chain::query()->firstOrCreate(
            ['code' => 'metro'],
            ['name' => 'Metro', 'is_active' => true]
        );
        $tradeCenters = $this->fetchTradeCenters();
        $storeExternalIds = [];
        $cityIds = [];
        $storesCount = 0;

        foreach ($tradeCenters as $tradeCenter) {
            $store = $this->upsertStore($chain, $tradeCenter);
            if (!$store) {
                continue;
            }

            $storeExternalIds[] = $store->external_id;
            if ($store->city_id) {
                $cityIds[$store->city_id] = true;
            }
            $storesCount++;
        }

        if ($storeExternalIds) {
            Store::query()
                ->where('chain_id', $chain->id)
                ->whereNotIn('external_id', $storeExternalIds)
                ->update(['is_active' => false]);
        }

        return [
            'cities' => count($cityIds),
            'stores' => $storesCount,
        ];
    }

    public function syncDefaultStore(Chain $chain): Store
    {
        $tradeCenter = $this->fetchTradeCenter((int) config('services.metro.default_trade_center_id'));

        return $this->upsertStore($chain, $tradeCenter, [
            'external_id' => (string) config('services.metro.default_store_id'),
            'name' => 'Metro',
            'city' => config('services.metro.default_city'),
            'address' => config('services.metro.default_address'),
        ]);
    }

    public function refreshStore(Chain $chain, Store $store): Store
    {
        $tradeCenter = $this->fetchTradeCenter($this->tradeCenterIdForStore($store));

        return $this->upsertStore($chain, $tradeCenter, [
            'external_id' => $store->external_id,
            'name' => $store->name,
            'city_id' => $store->city_id,
            'address' => $store->address,
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
        ]);
    }

    private function tradeCenterIdForStore(Store $store): int
    {
        if ((string) $store->external_id === (string) config('services.metro.default_store_id')) {
            return (int) config('services.metro.default_trade_center_id');
        }

        return (int) $store->external_id;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function fetchTradeCenters(): Collection
    {
        $response = Http::timeout(30)->get($this->baseUrl());

        if (! $response->ok()) {
            throw new RuntimeException('Metro tradecenters API returned HTTP '.$response->status());
        }

        $items = $response->json('data', []);

        if (!is_array($items)) {
            throw new RuntimeException('Metro tradecenters API returned invalid data.');
        }

        return collect($items)->filter(fn ($item) => is_array($item))->values();
    }

    private function fetchTradeCenter(int $tradeCenterId): ?array
    {
        if ($tradeCenterId <= 0) {
            return null;
        }

        $response = Http::timeout(20)->get($this->baseUrl().'/'.$tradeCenterId);

        if (! $response->ok()) {
            return null;
        }

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }

    private function upsertStore(Chain $chain, ?array $tradeCenter, array $fallback = []): ?Store
    {
        if (! $tradeCenter) {
            return $fallback ? $this->persister->upsertStore($chain, $fallback) : null;
        }

        $externalId = $tradeCenter['store_id'] ?? $fallback['external_id'] ?? null;
        if (!$externalId) {
            return null;
        }

        $payload = [
            'external_id' => (string) $externalId,
            'name' => $tradeCenter['name'] ?? $fallback['name'] ?? 'Metro',
            'city' => $tradeCenter['city'] ?? $fallback['city'] ?? null,
            'address' => $tradeCenter['address'] ?? $fallback['address'] ?? null,
            'latitude' => $tradeCenter['coordinates']['latitude'] ?? $fallback['latitude'] ?? null,
            'longitude' => $tradeCenter['coordinates']['longitude'] ?? $fallback['longitude'] ?? null,
        ];

        if (empty($payload['city']) && isset($fallback['city_id'])) {
            $payload['city_id'] = $fallback['city_id'];
        }

        return $this->persister->upsertStore($chain, $payload);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.metro.tradecenters_url'), '/');
    }
}
