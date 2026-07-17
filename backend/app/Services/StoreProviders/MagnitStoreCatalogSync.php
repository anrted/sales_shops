<?php

namespace App\Services\StoreProviders;

use App\Models\Chain;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MagnitStoreCatalogSync
{
    private const PAGE_SIZE = 1000;

    public function __construct(private readonly ProviderPersister $persister)
    {
    }

    /** @return array{cities:int, stores:int} */
    public function sync(): array
    {
        $chain = Chain::query()->where('code', 'magnit')->firstOrFail();
        $items = $this->fetchStores();
        $storeExternalIds = [];
        $cityIds = [];
        $storesCount = 0;

        foreach ($items as $item) {
            $store = $this->upsertStore($chain, $item);
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

    /** @param array<int, array<string, mixed>> $items */
    public function importFromYandex(array $items): array
    {
        $chain = Chain::query()->where('code', 'magnit')->firstOrFail();
        $cityIds = [];
        $storesCount = 0;

        foreach ($items as $item) {
            $store = $this->upsertYandexStore($chain, $item);
            if (!$store) {
                continue;
            }

            if ($store->city_id) {
                $cityIds[$store->city_id] = true;
            }

            $storesCount++;
        }

        return [
            'cities' => count($cityIds),
            'stores' => $storesCount,
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    public function importStores(array $items): array
    {
        $chain = Chain::query()->where('code', 'magnit')->firstOrFail();
        $cityIds = [];
        $storesCount = 0;

        foreach ($items as $item) {
            $store = $this->upsertStore($chain, $item);
            if (!$store) {
                continue;
            }

            if ($store->city_id) {
                $cityIds[$store->city_id] = true;
            }

            $storesCount++;
        }

        return [
            'cities' => count($cityIds),
            'stores' => $storesCount,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchStores(?string $region = null): array
    {
        $boxes = $this->boxes($region);
        if (!$boxes) {
            throw new RuntimeException('Magnit store boxes are not configured.');
        }

        return $this->fetchStoresForBoxes($boxes);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchStoresByBox(array $box): array
    {
        if (!isset($box['lat1'], $box['lon1'], $box['lat2'], $box['lon2'])) {
            throw new RuntimeException('Magnit store box is invalid.');
        }

        return $this->fetchStoresForBoxes([$box]);
    }

    /** @param array<int, array<string, mixed>> $boxes */
    private function fetchStoresForBoxes(array $boxes): array
    {
        $stores = [];

        foreach ($boxes as $box) {
            $offset = 0;

            while (true) {
                $items = $this->fetchStoresPage($box, $offset);

                foreach ($items as $item) {
                    $storeCode = $item['externalId']['storeCode'] ?? null;
                    if (!$storeCode) {
                        continue;
                    }

                    $stores[(string) $storeCode] = [
                        'storeCode' => (string) $storeCode,
                        'storeType' => $item['storeType'] ?? null,
                        'address' => $item['address'] ?? null,
                        'latitude' => $item['coordinates']['latitude'] ?? null,
                        'longitude' => $item['coordinates']['longitude'] ?? null,
                    ];
                }

                if (count($items) < self::PAGE_SIZE) {
                    break;
                }

                $offset += self::PAGE_SIZE;
            }
        }

        return array_values($stores);
    }

    private function upsertStore(Chain $chain, array $item): ?Store
    {
        $externalId = $item['storeCode'] ?? null;
        if (!$externalId) {
            return null;
        }

        return $this->persister->upsertStore($chain, [
            'external_id' => (string) $externalId,
            'type' => $item['storeType'] ?? null,
            'address' => $item['address'] ?? null,
            'latitude' => $item['latitude'] ?? null,
            'longitude' => $item['longitude'] ?? null,
        ]);
    }

    private function upsertYandexStore(Chain $chain, array $item): ?Store
    {
        $latitude = isset($item['latitude']) ? (float) $item['latitude'] : null;
        $longitude = isset($item['longitude']) ? (float) $item['longitude'] : null;
        $address = is_string($item['address'] ?? null) ? trim($item['address']) : null;
        $name = is_string($item['name'] ?? null) ? trim($item['name']) : null;
        $sourceId = is_string($item['source_id'] ?? null) ? trim($item['source_id']) : '';

        if (!$address && (!$latitude || !$longitude)) {
            return null;
        }

        $externalId = $sourceId !== ''
            ? 'yandex:'.$sourceId
            : 'yandex:'.Str::of($address.'|'.$latitude.'|'.$longitude)->lower()->slug('-');

        return $this->persister->upsertStore($chain, [
            'external_id' => (string) $externalId,
            'name' => $name ?: 'Магнит',
            'type' => 'yandex-map',
            'address' => $address,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchStoresPage(array $box, int $offset): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->withHeaders($this->headers())
            ->post((string) config('services.magnit.stores_url'), [
                'filters' => [
                    'geo' => [
                        'typeName' => 'box',
                        'leftTopPoint' => [
                            'latitude' => (float) $box['lat1'],
                            'longitude' => (float) $box['lon1'],
                        ],
                        'rightBottomPoint' => [
                            'latitude' => (float) $box['lat2'],
                            'longitude' => (float) $box['lon2'],
                        ],
                    ],
                    'storeTypeList' => [
                        'STORE_TYPE_MM',
                        'STORE_TYPE_GM',
                        'STORE_TYPE_MO',
                        'STORE_TYPE_ME',
                        'STORE_TYPE_MC',
                    ],
                ],
                'pagination' => [
                    'offset' => $offset,
                    'size' => self::PAGE_SIZE,
                ],
                'sorting' => [
                    'sortBy' => 'SORT_BY_CITY',
                    'sortType' => 'SORT_TYPE_ASC',
                ],
            ]);

        if (!$response->ok()) {
            throw new RuntimeException('Magnit stores API returned HTTP '.$response->status());
        }

        $items = $response->json('data', []);

        if (!is_array($items)) {
            throw new RuntimeException('Magnit stores API returned invalid data.');
        }

        return array_values(array_filter($items, static fn ($item): bool => is_array($item)));
    }

    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0',
            'Content-Type' => 'application/json',
        ];
    }

    private function boxes(?string $regionName = null): array
    {
        if (is_string($regionName) && $regionName !== '') {
            $regions = config('services.magnit.store_regions', []);
            $regionName = mb_strtolower($regionName);

            if (is_array($regions) && isset($regions[$regionName]) && is_array($regions[$regionName])) {
                return array_values(array_filter($regions[$regionName], static fn ($box): bool => is_array($box) && isset($box['lat1'], $box['lon1'], $box['lat2'], $box['lon2'])));
            }
        }

        $regionName = config('services.magnit.store_region');

        if (is_string($regionName) && $regionName !== '') {
            $regions = config('services.magnit.store_regions', []);
            $regionName = mb_strtolower($regionName);

            if (is_array($regions) && isset($regions[$regionName]) && is_array($regions[$regionName])) {
                return array_values(array_filter($regions[$regionName], static fn ($box): bool => is_array($box) && isset($box['lat1'], $box['lon1'], $box['lat2'], $box['lon2'])));
            }
        }

        $boxes = config('services.magnit.store_boxes', []);

        return is_array($boxes)
            ? array_values(array_filter($boxes, static fn ($box): bool => is_array($box) && isset($box['lat1'], $box['lon1'], $box['lat2'], $box['lon2'])))
            : [];
    }
}
