<?php

namespace App\Services\StoreProviders;

use App\Contracts\StoreProviderInterface;
use App\Data\ProviderResult;
use App\Models\Category;
use App\Models\Chain;
use App\Models\City;
use App\Models\Product;
use App\Models\Store;
use App\Services\ParseRunProgress;
use RuntimeException;

class LentaProvider implements StoreProviderInterface
{
    private const CATEGORY_STORE_TYPES = ['SM', 'HM'];
    private const MAX_ITEMS_PAGE_SIZE = 150;

    /** @var array<string, array<string, array{model:Category, leaf:bool, root:bool}>> */
    private array $categoryCache = [];

    public function __construct(
        private readonly ProviderPersister $persister,
        private readonly ParseRunProgress $progress,
        private readonly LentaStoreCatalogSync $storeCatalogSync,
        private readonly LentaApiClient $client,
    ) {
    }

    public function code(): string
    {
        return 'lenta';
    }

    /** @return array{store_types:int,categories:int} */
    public function syncCategoriesForStoreTypes(Chain $chain): array
    {
        $stores = $this->supportedStoresQuery($chain)
            ->orderBy('type')
            ->orderBy('external_id')
            ->get()
            ->unique(fn (Store $store): string => (string) $store->type)
            ->values();

        if ($stores->isEmpty()) {
            $this->storeCatalogSync->sync();
            $stores = $this->supportedStoresQuery($chain)
                ->orderBy('type')
                ->orderBy('external_id')
                ->get()
                ->unique(fn (Store $store): string => (string) $store->type)
                ->values();
        }

        $categoriesCount = 0;
        foreach ($stores as $store) {
            $categoriesCount += count($this->categoriesForStore($chain, $store));
        }

        return [
            'store_types' => $stores->count(),
            'categories' => $categoriesCount,
        ];
    }

    public function parse(Chain $chain, ?int $cityId = null, ?int $storeId = null): ProviderResult
    {
        $this->progress->update('Lenta: starting');

        $stores = $this->supportedStoresQuery($chain)
            ->when($cityId, fn ($query) => $query->where('city_id', $cityId))
            ->when($storeId, fn ($query) => $query->whereKey($storeId))
            ->get();

        if ($stores->isEmpty()) {
            $this->progress->update('Lenta: syncing stores');
            $this->storeCatalogSync->sync();
            $stores = $this->supportedStoresQuery($chain)
                ->when($cityId, fn ($query) => $query->where('city_id', $cityId))
                ->when($storeId, fn ($query) => $query->whereKey($storeId))
                ->get();
        }

        if ($stores->isEmpty()) {
            throw new RuntimeException($this->missingStoresMessage($cityId, $storeId));
        }

        $productsCount = 0;
        $offersCount = 0;
        $storesCount = 0;
        $totalStores = $stores->count();

        $this->progress->update(
            sprintf('Lenta: syncing stores complete, selected %d', $totalStores),
            [
                'stores_count' => 0,
                'products_count' => 0,
                'offers_count' => 0,
            ],
        );

        foreach ($stores as $store) {
            $this->progress->ensureNotCancelled();
            $storeContext = $this->storeContext($chain, $store);

            /** @var Store $activeStore */
            $activeStore = $storeContext['store'];
            $regionSlug = (string) $storeContext['region_slug'];
            $apiStoreId = (int) $storeContext['api_store_id'];

            if (!in_array((string) $activeStore->type, self::CATEGORY_STORE_TYPES, true)) {
                continue;
            }

            $storesCount++;

            $this->progress->update(
                sprintf('Lenta: store %d/%d, selecting pickup store', $storesCount, $totalStores),
                [
                    'stores_count' => $storesCount - 1,
                    'products_count' => $productsCount,
                    'offers_count' => $offersCount,
                ],
            );

            $this->progress->update(
                sprintf('Lenta: store %d/%d, loading categories', $storesCount, $totalStores),
                [
                    'stores_count' => $storesCount - 1,
                    'products_count' => $productsCount,
                    'offers_count' => $offersCount,
                ],
            );

            $categories = $this->categoriesForContext($chain, $activeStore, $regionSlug, $apiStoreId);
            $rootCategoryIds = array_keys(array_filter($categories, static fn (array $item): bool => $item['root']));

            foreach ($rootCategoryIds as $categoryExternalId) {
                $rootCategory = $categories[(string) $categoryExternalId]['model'] ?? null;

                $this->streamProductsForCategory(
                    $regionSlug,
                    (int) $categoryExternalId,
                    $rootCategory?->slug,
                    function (array $items, int $offset) use ($chain, $activeStore, $storesCount, $totalStores, $categories, $rootCategory, &$productsCount, &$offersCount): void {
                        $persisted = $this->persistItemsBatch($chain, $activeStore, $categories, $rootCategory, $items);
                        $productsCount += $persisted['products'];
                        $offersCount += $persisted['offers'];

                        $this->progress->update(
                            sprintf('Lenta: parsing items, store %d/%d, products %d, offset %d', $storesCount, $totalStores, $productsCount, $offset),
                            [
                                'stores_count' => $storesCount,
                                'products_count' => $productsCount,
                                'offers_count' => $offersCount,
                            ],
                        );
                    },
                );

                usleep(random_int(670_000, 1_500_000));
            }

            usleep(random_int(690_000, 1_250_000));
        }

        return new ProviderResult($storesCount, $productsCount, $offersCount);
    }

    private function supportedStoresQuery(Chain $chain)
    {
        return Store::query()
            ->where('chain_id', $chain->id)
            ->whereIn('type', self::CATEGORY_STORE_TYPES);
    }

    /** @return array<string, array{model:Category, leaf:bool, root:bool}> */
    private function categoriesForStore(Chain $chain, Store $store): array
    {
        $storeContext = $this->storeContext($chain, $store);

        /** @var Store $activeStore */
        $activeStore = $storeContext['store'];
        $regionSlug = (string) $storeContext['region_slug'];
        $apiStoreId = (int) $storeContext['api_store_id'];

        return $this->categoriesForContext($chain, $activeStore, $regionSlug, $apiStoreId);
    }

    /** @return array<string, array{model:Category, leaf:bool, root:bool}> */
    private function categoriesForContext(Chain $chain, Store $store, string $regionSlug, int $apiStoreId): array
    {
        $storeType = $this->normalizedStoreType($store);
        if ($storeType === null || !in_array($storeType, self::CATEGORY_STORE_TYPES, true)) {
            return [];
        }

        if (isset($this->categoryCache[$storeType])) {
            $this->client->selectPickupStore($regionSlug, $apiStoreId, $this->storeMeta($store));

            return $this->categoryCache[$storeType];
        }

        $storedCategories = $this->storedCategoriesForStoreType($chain, $storeType);
        if ($storedCategories !== []) {
            $this->client->selectPickupStore($regionSlug, $apiStoreId, $this->storeMeta($store));

            return $this->categoryCache[$storeType] = $storedCategories;
        }

        $this->client->selectPickupStore($regionSlug, $apiStoreId, $this->storeMeta($store));

        return $this->categoryCache[$storeType] = $this->fetchAndPersistCategories($chain, $regionSlug, $storeType);
    }

    /** @return array{store:Store, region_slug:string, api_store_id:int} */
    private function storeContext(Chain $chain, Store $store): array
    {
        $regionSlug = trim((string) config('services.lenta.default_domain'));
        $apiStoreId = is_numeric($store->external_id) ? (int) $store->external_id : 0;

        if ($regionSlug !== '' && $apiStoreId > 0) {
            return [
                'store' => $store,
                'region_slug' => $regionSlug,
                'api_store_id' => $apiStoreId,
            ];
        }

        $storeContext = $this->storeCatalogSync->refreshStore($chain, $store);
        if ($storeContext === null) {
            throw new RuntimeException(sprintf('Lenta pickup store [%s] was not found in remote catalog.', $store->external_id));
        }

        return $storeContext;
    }

    /** @return array<string, array{model:Category, leaf:bool, root:bool}> */
    private function storedCategoriesForStoreType(Chain $chain, string $storeType): array
    {
        $categories = Category::query()
            ->where('chain_id', $chain->id)
            ->where('store_type', $storeType)
            ->orderBy('level')
            ->orderBy('id')
            ->get();

        if ($categories->isEmpty()) {
            return [];
        }

        $parentIds = $categories
            ->pluck('parent_id')
            ->filter(static fn ($parentId): bool => $parentId !== null)
            ->map(static fn ($parentId): int => (int) $parentId)
            ->flip();

        $result = [];
        foreach ($categories as $category) {
            $result[(string) $category->external_id] = [
                'model' => $category,
                'leaf' => !$parentIds->has($category->id),
                'root' => $category->parent_id === null,
            ];
        }

        return $result;
    }

    /** @return array<string, array{model:Category, leaf:bool, root:bool}> */
    private function fetchAndPersistCategories(Chain $chain, string $regionSlug, ?string $storeType = null): array
    {
        $items = $this->client->fetchCategories($regionSlug);

        $persisted = [];

        foreach ($items as $item) {
            if (!is_array($item) || !is_numeric($item['id'] ?? null) || !is_string($item['name'] ?? null)) {
                continue;
            }

            $externalId = (string) (int) $item['id'];
            $persisted[$externalId] = [
                'model' => $this->persister->upsertCategory($chain, [
                    'external_id' => $externalId,
                    'store_type' => $storeType,
                    'name' => $item['name'],
                    'slug' => is_string($item['slug'] ?? null) && trim((string) $item['slug']) !== '' ? trim((string) $item['slug']) : null,
                    'level' => isset($item['level']) && is_numeric($item['level']) ? (int) $item['level'] : 0,
                ]),
                'parent_external_id' => isset($item['parentId']) && is_numeric($item['parentId']) && (int) $item['parentId'] > 0
                    ? (string) (int) $item['parentId']
                    : null,
                'leaf' => !((bool) ($item['hasChildren'] ?? false)),
                'root' => !isset($item['parentId']) || !is_numeric($item['parentId']) || (int) $item['parentId'] <= 0,
            ];
        }

        foreach ($persisted as $item) {
            $parentExternalId = $item['parent_external_id'];
            if ($parentExternalId === null) {
                continue;
            }

            $parent = $persisted[$parentExternalId]['model'] ?? null;
            if ($parent instanceof Category) {
                $item['model']->update(['parent_id' => $parent->id]);
            }
        }

        return array_map(
            static fn (array $item): array => [
                'model' => $item['model'],
                'leaf' => $item['leaf'],
                'root' => $item['root'],
            ],
            $persisted,
        );
    }

    private function normalizedStoreType(Store $store): ?string
    {
        $type = is_string($store->type) ? trim($store->type) : '';

        return $type !== '' ? $type : null;
    }

    private function hasCategoryCacheForStore(Store $store): bool
    {
        $storeType = $this->normalizedStoreType($store);

        return $storeType !== null && isset($this->categoryCache[$storeType]);
    }

    /** @return array<string, mixed> */
    private function storeMeta(Store $store): array
    {
        return [
            'external_id' => (string) $store->external_id,
            'name' => (string) ($store->name ?? ''),
            'address' => (string) ($store->address ?? ''),
            'type' => (string) ($store->type ?? ''),
        ];
    }

    private function streamProductsForCategory(string $regionSlug, int $categoryId, ?string $categorySlug, callable $handlePage): void
    {
        $offset = 0;
        $limit = min(
            self::MAX_ITEMS_PAGE_SIZE,
            max(1, (int) config('services.lenta.page_size', self::MAX_ITEMS_PAGE_SIZE)),
        );

        while (true) {
            $this->progress->ensureNotCancelled();
            $items = $this->client->fetchItemsPage($regionSlug, $categoryId, $limit, $offset, $categorySlug);
            if ($items === []) {
                break;
            }

            $handlePage($items, $offset);

            if (count($items) < $limit) {
                break;
            }

            $offset += $limit;
            $this->progress->update(sprintf('Lenta: parsing items, category %d, offset %d', $categoryId, $offset));
            usleep(random_int(680_000, 1_365_000));
        }
    }

    /**
     * @param  array<string, array{model:Category, leaf:bool, root:bool}>  $categories
     * @param  array<int, array<string, mixed>>  $items
     * @return array{products:int,offers:int}
     */
    private function persistItemsBatch(Chain $chain, Store $store, array $categories, ?Category $fallbackRoot, array $items): array
    {
        $productsCount = 0;
        $offersCount = 0;
        $batchSize = max(1, (int) config('services.lenta.product_batch_size', 150));

        foreach (array_chunk($items, $batchSize) as $chunk) {
            $productRows = [];
            $offerRows = [];

            foreach ($chunk as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $offerData = $this->normalizeOfferData($item);
                if ($offerData === null) {
                    continue;
                }

                try {
                    $name = $this->resolveProductName($item);
                } catch (RuntimeException) {
                    continue;
                }

                $externalId = (string) ($item['id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $category = $this->resolveCategoryForItem($item, $categories, $fallbackRoot);
                $productRows[$externalId] = [
                    'external_id' => $externalId,
                    'category_id' => $category?->id,
                    'name' => $name,
                    'slug' => $item['slug'] ?? null,
                    'image_url' => $this->extractImageUrl($item['images'] ?? null),
                    'unit' => $offerData['unit'],
                    'unit_size' => $offerData['unit_size'],
                    'raw_payload' => $item,
                ];
                $offerRows[$externalId] = $offerData;
            }

            if ($productRows === []) {
                continue;
            }

            $products = $this->persister->upsertProductsBatch($chain, array_values($productRows));
            if ($products === []) {
                continue;
            }

            $offerPayload = [];
            foreach ($offerRows as $externalId => $offerData) {
                $product = $products[$externalId] ?? null;
                if (!$product instanceof Product) {
                    continue;
                }

                $offerPayload[] = [
                    'product' => $product,
                    'store' => $store,
                    'data' => $offerData,
                ];
            }

            $offers = $this->persister->upsertOffersBatch($offerPayload);
            if ($offers === []) {
                continue;
            }

            $discountPayload = [];
            foreach ($offerPayload as $row) {
                $product = $row['product'];
                $offer = $offers[$product->id.'|'.$store->id] ?? null;
                if ($offer === null) {
                    continue;
                }

                $discountPayload[] = [
                    'offer' => $offer,
                    'data' => $row['data'],
                ];
            }

            $this->persister->upsertDiscountsBatch($discountPayload);
            $productsCount += count($products);
            $offersCount += count($discountPayload);
        }

        return [
            'products' => $productsCount,
            'offers' => $offersCount,
        ];
    }

    /** @param array<string, mixed> $item
     *  @return array<string, mixed>|null
     */
    private function normalizeOfferData(array $item): ?array
    {
        if (!is_numeric($item['id'] ?? null)) {
            return null;
        }

        $prices = is_array($item['prices'] ?? null) ? $item['prices'] : null;
        if ($prices === null || !is_numeric($prices['price'] ?? null)) {
            return null;
        }

        $price = round(((float) $prices['price']) / 100, 2);
        $oldPriceRaw = $prices['priceRegular'] ?? $prices['costRegular'] ?? null;
        $oldPrice = is_numeric($oldPriceRaw) ? round(((float) $oldPriceRaw) / 100, 2) : null;

        if ($price <= 0 || $oldPrice === null || $oldPrice <= $price) {
            return null;
        }

        $profit = round($oldPrice - $price, 2);
        $discountPercent = round(($profit / $oldPrice) * 100, 2);
        if ($discountPercent <= 0) {
            return null;
        }

        $units = is_array($item['units'] ?? null) ? $item['units'] : [];
        $itemUnit = is_string($units['itemUnit'] ?? null) ? trim((string) $units['itemUnit']) : null;
        $saleUnit = is_string($units['saleUnit'] ?? null) ? trim((string) $units['saleUnit']) : null;
        $unitSize = null;
        if (is_numeric($units['saleUnitQuantity'] ?? null) && (float) $units['saleUnitQuantity'] > 0) {
            $unitSize = round((float) $units['saleUnitQuantity'], 3);
        } elseif (is_numeric($units['itemUnitQuantity'] ?? null) && (float) $units['itemUnitQuantity'] > 0) {
            $unitSize = round((float) $units['itemUnitQuantity'], 3);
        }

        return [
            'price' => $price,
            'old_price' => $oldPrice,
            'stock' => isset($item['count']) && is_numeric($item['count']) ? (int) $item['count'] : null,
            'unit' => $saleUnit ?: $itemUnit,
            'unit_size' => $unitSize,
            'discount_percent' => $discountPercent,
            'profit' => $profit,
            'metadata' => [
                'prices' => $prices,
                'badges' => $item['badges'] ?? null,
                'quantityDiscount' => $item['quantityDiscount'] ?? [],
                'quantityDiscountPromo' => $item['quantityDiscountPromo'] ?? [],
                'storeId' => $item['storeId'] ?? null,
            ],
        ];
    }

    /** @param array<string, mixed> $item */
    private function resolveProductName(array $item): string
    {
        $name = $item['name'] ?? $item['display']['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw new RuntimeException('Lenta product does not contain a valid name.');
        }

        return trim($name);
    }

    private function extractImageUrl(mixed $images): ?string
    {
        if (!is_array($images)) {
            return null;
        }

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            foreach (['original', 'large', 'medium', 'preview', 'icon'] as $field) {
                $value = $image[$field] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array{model:Category, leaf:bool, root:bool}>  $categories
     */
    private function resolveCategoryForItem(array $item, array $categories, ?Category $fallbackRoot): ?Category
    {
        $externalId = $this->extractCategoryExternalId($item);
        if ($externalId !== null && isset($categories[$externalId]['model'])) {
            return $categories[$externalId]['model'];
        }

        return $fallbackRoot;
    }

    /** @param array<string, mixed> $item */
    private function extractCategoryExternalId(array $item): ?string
    {
        $nestedCategoryId = $item['category']['id'] ?? null;
        if (is_numeric($nestedCategoryId)) {
            return (string) (int) $nestedCategoryId;
        }

        $directCategoryId = $item['categoryId'] ?? null;
        if (is_numeric($directCategoryId)) {
            return (string) (int) $directCategoryId;
        }

        $categoryIds = $item['category_id'] ?? null;
        if (is_array($categoryIds) && isset($categoryIds[0]) && is_numeric($categoryIds[0])) {
            return (string) (int) $categoryIds[0];
        }

        if (is_numeric($categoryIds)) {
            return (string) (int) $categoryIds;
        }

        return null;
    }

    private function missingStoresMessage(?int $cityId, ?int $storeId): string
    {
        if ($storeId !== null) {
            return sprintf('Lenta parse could not find supported store [%d].', $storeId);
        }

        if ($cityId !== null) {
            $cityName = City::query()->whereKey($cityId)->value('name');

            return $cityName
                ? sprintf('Lenta parse could not find supported stores for city "%s".', $cityName)
                : sprintf('Lenta parse could not find supported stores for city_id [%d].', $cityId);
        }

        return 'Lenta parse requires a selected city or a specific store.';
    }
}
