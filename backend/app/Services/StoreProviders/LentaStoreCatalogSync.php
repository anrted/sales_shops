<?php

namespace App\Services\StoreProviders;

use App\Models\Chain;
use App\Models\Store;
class LentaStoreCatalogSync
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $catalog = null;

    public function __construct(
        private readonly ProviderPersister $persister,
        private readonly LentaApiClient $client,
    ) {
    }

    /** @return array{cities:int, stores:int} */
    public function sync(): array
    {
        $chain = Chain::query()->where('code', 'lenta')->firstOrFail();
        $catalog = $this->catalog();
        $storeExternalIds = [];
        $cityIds = [];

        foreach ($catalog as $item) {
            $store = $this->persister->upsertStore($chain, $item);
            $storeExternalIds[] = $store->external_id;

            if ($store->city_id) {
                $cityIds[$store->city_id] = true;
            }
        }

        if ($storeExternalIds) {
            Store::query()
                ->where('chain_id', $chain->id)
                ->whereNotIn('external_id', $storeExternalIds)
                ->update(['is_active' => false]);
        }

        return [
            'cities' => count($cityIds),
            'stores' => count($catalog),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $catalog = [];
        $regions = $this->client->fetchRegions();

        foreach ($regions as $region) {
            $stores = $this->client->fetchPickupStoresForRegion($region);

            foreach ($stores as $store) {
                $normalized = $this->normalizeStore($region, $store);
                if ($normalized === null) {
                    continue;
                }

                $catalog[$normalized['external_id']] = $normalized;
            }
        }

        return $this->catalog = $catalog;
    }

    /** @return array{store:Store, region_slug:string, api_store_id:int}|null */
    public function refreshStore(Chain $chain, Store $store): ?array
    {
        $item = $this->catalog()[(string) $store->external_id] ?? null;
        if ($item === null) {
            return null;
        }

        return [
            'store' => $this->persister->upsertStore($chain, $item),
            'region_slug' => (string) $item['region_slug'],
            'api_store_id' => (int) $item['api_store_id'],
        ];
    }

    /** @param array<string, mixed> $region
     *  @param array<string, mixed> $store
     *  @return array<string, mixed>|null
     */
    private function normalizeStore(array $region, array $store): ?array
    {
        $externalId = $store['id'] ?? null;
        $regionSlug = $region['slug'] ?? null;

        if (!is_numeric($externalId) || !is_string($regionSlug) || $regionSlug === '') {
            return null;
        }

        return [
            'external_id' => (string) (int) $externalId,
            'name' => $store['title'] ?? $store['alias'] ?? 'Лента',
            'type' => $store['marketType'] ?? null,
            'address' => $store['addressFull'] ?? $store['addressShort'] ?? null,
            'latitude' => $store['coordinates']['latitude'] ?? null,
            'longitude' => $store['coordinates']['longitude'] ?? null,
            'city' => $this->extractCityName($store),
            'region_slug' => $regionSlug,
            'region_id' => isset($store['regionId']) && is_numeric($store['regionId']) ? (int) $store['regionId'] : null,
            'api_store_id' => (int) $externalId,
        ];
    }

    /** @param array<string, mixed> $store */
    private function extractCityName(array $store): ?string
    {
        $address = $store['addressShort'] ?? $store['addressFull'] ?? null;
        if (!is_string($address) || trim($address) === '') {
            return null;
        }

        $parts = preg_split('/,\s*/u', trim($address)) ?: [];
        if ($parts === []) {
            return null;
        }

        $city = trim((string) $parts[0]);

        return $city !== '' ? $city : null;
    }
}
