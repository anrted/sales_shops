<?php

namespace App\Services\StoreProviders;

use App\Contracts\StoreProviderInterface;
use App\Data\ProviderResult;
use App\Models\Category;
use App\Models\Chain;
use App\Models\Store;
use App\Services\ParseRunProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MetroProvider implements StoreProviderInterface
{
    private const PAGE_SIZE = 5000;
    private const UPSERT_CHUNK_SIZE = 500;

    public function __construct(
        private readonly ProviderPersister $persister,
        private readonly ParseRunProgress $progress,
        private readonly MetroStoreCatalogSync $storeCatalogSync,
    ) {
    }

    public function code(): string
    {
        return 'metro';
    }

    public function parse(Chain $chain, ?int $cityId = null, ?int $storeId = null): ProviderResult
    {
        $this->progress->update('Metro: загрузка магазинов');

        $stores = Store::query()
            ->where('chain_id', $chain->id)
            ->when($cityId, fn ($query) => $query->where('city_id', $cityId))
            ->when($storeId, fn ($query) => $query->whereKey($storeId))
            ->get();

        if ($stores->isEmpty()) {
            $stores = collect([
                $this->storeCatalogSync->syncDefaultStore($chain),
            ]);
        }

        $productsCount = 0;
        $offersCount = 0;

        foreach ($stores as $storeIndex => $store) {
            $storeNumber = $storeIndex + 1;
            $storesTotal = $stores->count();

            $this->progress->update(
                sprintf('Metro: магазин %d/%d, загрузка категорий', $storeNumber, $storesTotal),
                ['stores_count' => $storeIndex],
            );
            $categories = $this->fetchAndPersistCategories($chain, (int) $store->external_id);

            $this->streamProductPages((int) $store->external_id, $storeNumber, $storesTotal, function (array $items, int $page, int $from) use ($chain, $store, $categories, $storeIndex, $storeNumber, $storesTotal, &$productsCount, &$offersCount): void {
                $this->progress->update(
                    sprintf('Metro: магазин %d/%d, сохранение страницы %d (%d товаров)', $storeNumber, $storesTotal, $page, count($items)),
                    [
                        'stores_count' => $storeIndex,
                        'products_count' => $productsCount,
                        'offers_count' => $offersCount,
                    ],
                );

                $result = $this->persistProductsPage($chain, $store, $categories, $items);
                $productsCount += $result['products'];
                $offersCount += $result['offers'];

                $this->progress->update(
                    sprintf('Metro: магазин %d/%d, страница %d сохранена, offset %d', $storeNumber, $storesTotal, $page, $from),
                    [
                        'stores_count' => $storeIndex,
                        'products_count' => $productsCount,
                        'offers_count' => $offersCount,
                    ],
                );
            });

            $this->progress->update(
                sprintf('Metro: магазин %d/%d обработан', $storeNumber, $storesTotal),
                [
                    'stores_count' => $storeIndex + 1,
                    'products_count' => $productsCount,
                    'offers_count' => $offersCount,
                ],
            );
        }

        return new ProviderResult($stores->count(), $productsCount, $offersCount);
    }

    /** @return array<string, Category> */
    private function fetchAndPersistCategories(Chain $chain, int $storeId): array
    {
        $storedCategories = $this->storedCategories($chain);
        if ($storedCategories !== []) {
            return $storedCategories;
        }

        $query = <<<'GQL'
query Category($storeId: Int!, $size: Int) {
  search(storeId: $storeId) {
    categories(size: $size) {
      id
      name
      parent_id
    }
  }
}
GQL;

        $categories = Http::timeout(30)
            ->post((string) config('services.metro.api_url'), [
                'query' => $query,
                'variables' => [
                    'storeId' => $storeId,
                    'size' => 1000,
                ],
            ])
            ->json('data.search.categories', []);

        $persisted = [];

        foreach ($categories as $item) {
            if (empty($item['id']) || empty($item['name'])) {
                continue;
            }

            $persisted[(string) $item['id']] = $this->persister->upsertCategory($chain, [
                'external_id' => (string) $item['id'],
                'name' => $item['name'],
                'level' => empty($item['parent_id']) ? 0 : 1,
            ]);
        }

        foreach ($categories as $item) {
            if (empty($item['id']) || empty($item['parent_id'])) {
                continue;
            }

            $category = $persisted[(string) $item['id']] ?? null;
            $parent = $persisted[(string) $item['parent_id']] ?? null;

            if ($category && $parent) {
                $category->update(['parent_id' => $parent->id, 'level' => 1]);
            }
        }

        return $persisted;
    }

    /** @return array<string, Category> */
    private function storedCategories(Chain $chain): array
    {
        return Category::query()
            ->where('chain_id', $chain->id)
            ->whereNull('store_type')
            ->orderBy('level')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Category $category): string => (string) $category->external_id)
            ->all();
    }

    private function streamProductPages(int $storeId, int $storeNumber, int $storesTotal, callable $handlePage): void
    {
        $query = <<<'GQL'
query Search($storeId: Int!, $size: Int!, $sort: Sort, $from: Int, $inStock: Boolean) {
  search(storeId: $storeId, inStock: $inStock) {
    products(size: $size, sort: $sort, from: $from) {
      products {
        slug
        name
        article
        stocks {
          value
          prices_per_unit { price old_price discount }
          prices { price old_price discount levels { count price } }
        }
        category_id
        packing { size }
        images
      }
    }
  }
}
GQL;

        $from = 0;
        $page = 1;

        while (true) {
            $this->progress->update(
                sprintf('Metro: магазин %d/%d, загрузка страницы %d, offset %d', $storeNumber, $storesTotal, $page, $from),
            );

            $products = Http::timeout(60)
                ->post((string) config('services.metro.api_url'), [
                    'query' => $query,
                    'variables' => [
                        'storeId' => $storeId,
                        'size' => self::PAGE_SIZE,
                        'sort' => 'discountDesc',
                        'from' => $from,
                        'inStock' => true,
                    ],
                ])
                ->json('data.search.products.products', []);

            if (!$products) {
                break;
            }

            $handlePage($products, $page, $from);

            if (count($products) < self::PAGE_SIZE) {
                break;
            }

            $from += self::PAGE_SIZE;
            $page++;
            usleep(random_int(400_000, 900_000));
        }
    }

    /** @param array<string, Category> $categories */
    private function persistProductsPage(Chain $chain, Store $store, array $categories, array $items): array
    {
        $now = now();
        $productRows = [];
        $offerPayloads = [];

        foreach ($items as $item) {
            if (empty($item['article']) || empty($item['name'])) {
                continue;
            }

            $offerData = $this->normalizeOfferData($item);
            if ($offerData === null) {
                continue;
            }

            $externalId = (string) $item['article'];
            $category = !empty($item['category_id']) ? ($categories[(string) $item['category_id']] ?? null) : null;

            $productRows[$externalId] = [
                'chain_id' => $chain->id,
                'category_id' => $category?->id,
                'external_id' => $externalId,
                'name' => $item['name'],
                'slug' => $item['slug'] ?? Str::slug($item['name']),
                'image_url' => $this->extractImageUrl($item['images'] ?? null),
                'unit' => null,
                'unit_size' => $offerData['packing_size'],
                'raw_payload' => json_encode($item, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $offerPayloads[$externalId] = $offerData;
        }

        if (!$productRows) {
            return ['products' => 0, 'offers' => 0];
        }

        return DB::transaction(function () use ($chain, $store, $productRows, $offerPayloads, $now): array {
            foreach (array_chunk(array_values($productRows), self::UPSERT_CHUNK_SIZE) as $chunk) {
                $this->progress->ensureNotCancelled();
                DB::table('products')->upsert(
                    $chunk,
                    ['chain_id', 'external_id'],
                    ['category_id', 'name', 'slug', 'image_url', 'unit', 'unit_size', 'raw_payload', 'updated_at'],
                );
            }

            $productIds = DB::table('products')
                ->where('chain_id', $chain->id)
                ->whereIn('external_id', array_keys($productRows))
                ->pluck('id', 'external_id');

            $offerRows = [];
            foreach ($offerPayloads as $externalId => $offerData) {
                $productId = $productIds[$externalId] ?? null;
                if (!$productId) {
                    continue;
                }

                $offerRows[] = [
                    'product_id' => $productId,
                    'store_id' => $store->id,
                    'price' => $offerData['price'],
                    'old_price' => $offerData['old_price'],
                    'unit_price' => null,
                    'stock' => $offerData['stock'],
                    'in_stock' => true,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($offerRows, self::UPSERT_CHUNK_SIZE) as $chunk) {
                $this->progress->ensureNotCancelled();
                DB::table('offers')->upsert(
                    $chunk,
                    ['product_id', 'store_id'],
                    ['price', 'old_price', 'unit_price', 'stock', 'in_stock', 'last_seen_at', 'updated_at'],
                );
            }

            $offerIds = DB::table('offers')
                ->where('store_id', $store->id)
                ->whereIn('product_id', $productIds->values()->all())
                ->pluck('id', 'product_id');

            $discountRows = [];
            foreach ($offerPayloads as $externalId => $offerData) {
                $productId = $productIds[$externalId] ?? null;
                $offerId = $productId ? ($offerIds[$productId] ?? null) : null;
                if (!$offerId) {
                    continue;
                }

                $discountRows[] = [
                    'offer_id' => $offerId,
                    'discount_percent' => $offerData['max_discount_percent'],
                    'profit' => $offerData['max_profit'],
                    'starts_at' => null,
                    'ends_at' => null,
                    'metadata' => json_encode([
                        'base_discount_percent' => $offerData['base_discount_percent'],
                        'levels' => $offerData['levels'],
                        'best_level_count' => $offerData['best_level_count'],
                        'best_level_price' => $offerData['best_level_price'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($discountRows, self::UPSERT_CHUNK_SIZE) as $chunk) {
                $this->progress->ensureNotCancelled();
                DB::table('discounts')->upsert(
                    $chunk,
                    ['offer_id'],
                    ['discount_percent', 'profit', 'starts_at', 'ends_at', 'metadata', 'updated_at'],
                );
            }

            return ['products' => count($productRows), 'offers' => count($discountRows)];
        });
    }

    /** @return array<string, mixed>|null */
    private function normalizeOfferData(array $item): ?array
    {
        $stock = $item['stocks'][0] ?? null;
        if (!is_array($stock)) {
            return null;
        }

        $packingSize = $this->normalizePositiveFloat($item['packing']['size'] ?? null, 1.0);
        $stockValue = $this->normalizeNullableFloat($stock['value'] ?? null);
        $pricesPerUnit = $stock['prices_per_unit'] ?? null;
        $prices = $stock['prices'] ?? null;

        $basePrice = null;
        if (is_array($pricesPerUnit) && $this->isNumeric($pricesPerUnit['price'] ?? null)) {
            $basePrice = (float) $pricesPerUnit['price'];
        } elseif (is_array($prices) && $this->isNumeric($prices['price'] ?? null)) {
            $basePrice = (float) $prices['price'];
            if ($this->isWeightPacking($packingSize)) {
                $basePrice = round($basePrice / $packingSize, 2);
            }
        }

        if ($basePrice === null) {
            return null;
        }

        $oldPrice = null;
        if (is_array($pricesPerUnit) && $this->isNumeric($pricesPerUnit['old_price'] ?? null)) {
            $oldPrice = (float) $pricesPerUnit['old_price'];
        } elseif (is_array($prices) && $this->isNumeric($prices['old_price'] ?? null)) {
            $oldPrice = (float) $prices['old_price'];
            if ($this->isWeightPacking($packingSize)) {
                $oldPrice = round($oldPrice / $packingSize, 2);
            }
        }

        $baseDiscount = null;
        if (is_array($pricesPerUnit) && $this->isNumeric($pricesPerUnit['discount'] ?? null)) {
            $baseDiscount = (float) $pricesPerUnit['discount'];
        } elseif (is_array($prices) && $this->isNumeric($prices['discount'] ?? null)) {
            $baseDiscount = (float) $prices['discount'];
        }

        if ($oldPrice === null && $baseDiscount !== null && $baseDiscount > 0 && $baseDiscount < 100) {
            $oldPrice = round($basePrice / (1 - ($baseDiscount / 100)), 2);
        }

        $comparablePrice = ($oldPrice !== null && $oldPrice > $basePrice) ? $oldPrice : $basePrice;
        if ($baseDiscount === null) {
            $baseDiscount = $comparablePrice > 0 && $basePrice < $comparablePrice
                ? round((($comparablePrice - $basePrice) / $comparablePrice) * 100, 2)
                : 0.0;
        }

        $levels = [];
        $bestLevelCount = null;
        $bestLevelPrice = null;
        $bestLevelDiscount = 0.0;

        if (is_array($prices) && is_array($prices['levels'] ?? null)) {
            foreach ($prices['levels'] as $level) {
                if (!is_array($level) || ! $this->isNumeric($level['count'] ?? null) || ! $this->isNumeric($level['price'] ?? null)) {
                    continue;
                }

                $count = (int) $level['count'];
                $levelPrice = round((float) $level['price'], 2);
                if ($count <= 0 || $levelPrice <= 0) {
                    continue;
                }

                $levelDiscount = $comparablePrice > 0
                    ? round((($comparablePrice - $levelPrice) / $comparablePrice) * 100, 2)
                    : 0.0;

                $levels[] = [
                    'count' => $count,
                    'price' => $levelPrice,
                    'discount_percent' => max(0, $levelDiscount),
                ];

                if ($bestLevelPrice === null || $levelDiscount > $bestLevelDiscount) {
                    $bestLevelPrice = $levelPrice;
                    $bestLevelCount = $count;
                    $bestLevelDiscount = max(0, $levelDiscount);
                }
            }
        }

        if ($levels) {
            usort($levels, static fn (array $a, array $b): int => $a['count'] <=> $b['count']);
        }

        $maxDiscount = max((float) $baseDiscount, $bestLevelDiscount);
        $bestComparablePrice = $bestLevelDiscount > (float) $baseDiscount && $bestLevelPrice !== null ? $bestLevelPrice : $basePrice;

        return [
            'price' => round($basePrice, 2),
            'old_price' => $oldPrice !== null ? round($oldPrice, 2) : round($basePrice, 2),
            'stock' => $stockValue !== null ? (int) floor($stockValue) : null,
            'packing_size' => round($packingSize, 3),
            'base_discount_percent' => round((float) $baseDiscount, 2),
            'max_discount_percent' => round($maxDiscount, 2),
            'max_profit' => round(max(0, $comparablePrice - $bestComparablePrice), 2),
            'levels' => $levels,
            'best_level_count' => $bestLevelCount,
            'best_level_price' => $bestLevelPrice,
        ];
    }

    private function extractImageUrl(mixed $images): ?string
    {
        if (!is_array($images)) {
            return null;
        }

        foreach ($images as $image) {
            if (is_string($image) && trim($image) !== '') {
                return trim($image);
            }
        }

        return null;
    }

    private function isNumeric(mixed $value): bool
    {
        return is_numeric($value);
    }

    private function normalizePositiveFloat(mixed $value, float $fallback): float
    {
        return $this->isNumeric($value) && (float) $value > 0 ? (float) $value : $fallback;
    }

    private function normalizeNullableFloat(mixed $value): ?float
    {
        return $this->isNumeric($value) ? (float) $value : null;
    }

    private function isWeightPacking(float $packingSize): bool
    {
        return abs($packingSize - 1.0) > 0.0001;
    }
}
