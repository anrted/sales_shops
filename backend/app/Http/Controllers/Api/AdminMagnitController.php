<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Services\StoreProviders\MagnitProvider;
use App\Services\StoreProviders\MagnitStoreCatalogSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMagnitController extends Controller
{
    public function syncStores(MagnitStoreCatalogSync $sync): JsonResponse
    {
        return response()->json([
            'item' => $sync->sync(),
        ]);
    }

    public function syncCategories(MagnitProvider $provider): JsonResponse
    {
        $chain = Chain::query()->where('code', 'magnit')->firstOrFail();

        return response()->json([
            'item' => $provider->syncCategoriesForStoreTypes($chain, true),
        ]);
    }

    public function importYandexStores(Request $request, MagnitStoreCatalogSync $sync): JsonResponse
    {
        $validated = $request->validate([
            'stores' => ['required', 'array'],
            'stores.*.source_id' => ['nullable', 'string', 'max:255'],
            'stores.*.name' => ['nullable', 'string', 'max:255'],
            'stores.*.address' => ['nullable', 'string', 'max:1000'],
            'stores.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'stores.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return response()->json([
            'item' => $sync->importFromYandex($validated['stores']),
        ]);
    }

    public function importStores(Request $request, MagnitStoreCatalogSync $sync): JsonResponse
    {
        $validated = $request->validate([
            'stores' => ['required', 'array'],
            'stores.*.storeCode' => ['required', 'string', 'max:255'],
            'stores.*.storeType' => ['nullable', 'string', 'max:255'],
            'stores.*.address' => ['nullable', 'string', 'max:1000'],
            'stores.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'stores.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return response()->json([
            'item' => $sync->importStores($validated['stores']),
        ]);
    }

    public function region(Request $request): JsonResponse
    {
        $queryRegion = $request->query('region');
        $storeRegion = is_string($queryRegion) && $queryRegion !== '' ? mb_strtolower($queryRegion) : config('services.magnit.store_region');
        $regions = config('services.magnit.store_regions', []);
        $activeBoxes = [];

        if (is_string($storeRegion) && $storeRegion !== '') {
            if (is_array($regions) && isset($regions[$storeRegion]) && is_array($regions[$storeRegion])) {
                $activeBoxes = $regions[$storeRegion];
            }
        }

        if (!$activeBoxes) {
            $activeBoxes = config('services.magnit.store_boxes', []);
        }

        $activeBoxes = array_values(array_filter($activeBoxes, static fn ($box): bool => is_array($box) && isset($box['lat1'], $box['lon1'], $box['lat2'], $box['lon2'])));

        return response()->json([
            'item' => [
                'store_region' => $storeRegion,
                'store_boxes' => $activeBoxes,
                'regions' => array_values(array_filter(array_keys($regions), static fn ($name): bool => is_string($name) && $name !== '')),
            ],
        ]);
    }

    public function stores(Request $request, MagnitStoreCatalogSync $sync): JsonResponse
    {
        $region = $request->query('region');
        $hasBox = $request->has(['lat1', 'lon1', 'lat2', 'lon2']);
        $stores = $hasBox
            ? $sync->fetchStoresByBox([
                'lat1' => (float) $request->query('lat1'),
                'lon1' => (float) $request->query('lon1'),
                'lat2' => (float) $request->query('lat2'),
                'lon2' => (float) $request->query('lon2'),
            ])
            : $sync->fetchStores(is_string($region) ? $region : null);

        return response()->json([
            'item' => [
                'region' => $region,
                'stores' => $stores,
            ],
        ]);
    }
}
