<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Chain;
use App\Models\City;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function cities(): JsonResponse
    {
        return response()->json([
            'items' => City::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function chains(): JsonResponse
    {
        return response()->json([
            'items' => Chain::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $topOnly = $request->boolean('top_only', true);

        $items = Category::query()
            ->with('chain:id,code,name')
            ->when($request->string('chain')->isNotEmpty(), function ($query) use ($request): void {
                $query->whereHas('chain', fn ($chain) => $chain->where('code', $request->string('chain')));
            })
            ->when($topOnly, function ($query): void {
                $query->where(fn ($nested) => $nested->whereNull('parent_id')->orWhere('level', 0));
            })
            ->orderBy('name')
            ->get(['id', 'chain_id', 'parent_id', 'external_id', 'store_type', 'name', 'level']);

        if ($topOnly) {
            $items = $items
                ->unique(static fn (Category $category): string => mb_strtolower($category->name))
                ->values();
        }

        return response()->json(['items' => $items]);
    }

    public function stores(Request $request): JsonResponse
    {
        $query = Store::query()
            ->with(['chain:id,code,name', 'city:id,name'])
            ->when($request->integer('city_id'), fn ($query, $cityId) => $query->where('city_id', $cityId))
            ->when($request->string('chain')->isNotEmpty(), function ($query) use ($request): void {
                $query->whereHas('chain', fn ($chain) => $chain->where('code', $request->string('chain')));
            });

        $total = (clone $query)->count();
        $items = $query
            ->orderBy('address')
            ->get();

        return response()->json([
            'items' => $items,
            'total' => $total,
        ]);
    }

    public function discounts(Request $request): JsonResponse
    {
        $limit = min(100, max(1, $request->integer('limit', 48)));
        $offset = max(0, $request->integer('offset', 0));
        $sort = $request->string('sort', 'discount')->toString();
        $cityId = $request->integer('city_id');
        $chainCode = $request->string('chain')->toString();
        $search = trim($request->string('q')->toString());
        $usePhpSearch = $search !== '' && DB::connection()->getDriverName() === 'sqlite';
        $polygon = $this->parsePolygon($request->string('polygon')->toString());
        $storeIdsInPolygon = $polygon ? $this->storeIdsWithinPolygon($polygon, $cityId, $chainCode) : null;

        $query = Product::query()
            ->select('products.*')
            ->with(['chain:id,code,name', 'category:id,name'])
            ->join('offers', 'offers.product_id', '=', 'products.id')
            ->join('stores', 'stores.id', '=', 'offers.store_id')
            ->join('discounts', 'discounts.offer_id', '=', 'offers.id')
            ->where('offers.in_stock', true)
            ->where('offers.last_seen_at', '>=', now()->subDay())
            ->when($cityId, fn ($q) => $q->where('stores.city_id', $cityId))
            ->when($storeIdsInPolygon !== null, function ($q) use ($storeIdsInPolygon): void {
                if ($storeIdsInPolygon === []) {
                    $q->whereRaw('1 = 0');
                    return;
                }

                $q->whereIn('stores.id', $storeIdsInPolygon);
            })
            ->when($request->boolean('actual_only', true), function ($q): void {
                $q->where(function ($nested): void {
                    $nested->whereNull('discounts.ends_at')->orWhere('discounts.ends_at', '>=', now());
                });
            })
            ->when($chainCode !== '', function ($q) use ($chainCode): void {
                $q->whereHas('chain', fn ($chain) => $chain->where('code', $chainCode));
            })
            ->when($request->integer('category'), function ($q, $categoryId): void {
                $q->whereIn('products.category_id', $this->categoryWithDescendantIds((int) $categoryId));
            })
            ->when($search !== '' && !$usePhpSearch, function ($q) use ($search): void {
                $q->whereRaw('LOWER(products.name) LIKE ?', ['%'.mb_strtolower($search).'%']);
            })
            ->groupBy('products.id');

        $query->selectRaw('MIN(offers.price) as best_price');
        $query->selectRaw('MAX(offers.old_price) as old_price');
        $query->selectRaw('MAX(discounts.discount_percent) as best_discount');
        $query->selectRaw('MAX(discounts.profit) as best_profit');
        $query->selectRaw('MIN(offers.unit_price) as best_unit_price');
        $query->selectRaw('COUNT(DISTINCT stores.id) as stores_count');

        match ($sort) {
            'profit' => $query->orderByDesc('best_profit'),
            'price' => $query->orderBy('best_price'),
            'unit_price' => $query->orderByRaw('best_unit_price ASC NULLS LAST'),
            default => $query->orderByDesc('best_discount'),
        };

        if ($usePhpSearch) {
            $matchedProducts = $query
                ->get()
                ->filter(fn (Product $product): bool => mb_stripos($product->name, $search) !== false)
                ->values();

            $total = $matchedProducts->count();
            $products = $matchedProducts->slice($offset, $limit)->values();
        } else {
            $total = (clone $query)->get()->count();
            $products = $query->limit($limit)->offset($offset)->get();
        }

        $offerMap = $this->bestOffersForProducts($products->pluck('id')->all(), $cityId);

        return response()->json([
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'items' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'image_url' => $product->image_url,
                'chain' => $product->chain,
                'category' => $product->category,
                'best_price' => (float) $product->best_price,
                'old_price' => $product->old_price !== null && (float) $product->old_price > (float) $product->best_price
                    ? (float) $product->old_price
                    : null,
                'discount_percent' => (float) $product->best_discount,
                'profit' => (float) $product->best_profit,
                'unit_price' => $product->best_unit_price !== null ? (float) $product->best_unit_price : null,
                'stores_count' => (int) $product->stores_count,
                'levels' => $this->bestLevelsForProduct($offerMap[$product->id] ?? []),
                'stores' => $offerMap[$product->id] ?? [],
            ]),
        ]);
    }

    public function product(Product $product, Request $request): JsonResponse
    {
        $cityId = $request->integer('city_id');
        $product->load(['chain:id,code,name', 'category:id,name']);
        $offers = $this->bestOffersForProducts([$product->id], $cityId, null)[$product->id] ?? [];
        $bestPrice = collect($offers)->min('price');
        $bestOldPrice = collect($offers)->max('old_price');
        $bestDiscount = collect($offers)->max('discount_percent');
        $bestProfit = collect($offers)->max('profit');
        $bestUnitPrice = collect($offers)->min(fn ($offer) => $offer->unit_price ?? INF);

        $bestUnitPrice = $bestUnitPrice === INF ? null : $bestUnitPrice;

        return response()->json([
            'item' => [
                'id' => $product->id,
                'name' => $product->name,
                'image_url' => $product->image_url,
                'chain' => $product->chain,
                'category' => $product->category,
                'best_price' => $bestPrice !== null ? (float) $bestPrice : 0.0,
                'old_price' => $bestOldPrice !== null && $bestPrice !== null && (float) $bestOldPrice > (float) $bestPrice
                    ? (float) $bestOldPrice
                    : null,
                'discount_percent' => $bestDiscount !== null ? (float) $bestDiscount : 0.0,
                'profit' => $bestProfit !== null ? (float) $bestProfit : 0.0,
                'unit_price' => $bestUnitPrice !== null ? (float) $bestUnitPrice : null,
                'stores_count' => count($offers),
                'levels' => $this->bestLevelsForProduct($offers),
                'offers' => $offers,
            ],
        ]);
    }

    private function bestOffersForProducts(array $productIds, ?int $cityId, ?int $limit = 5): array
    {
        if (!$productIds) {
            return [];
        }

        $grouped = DB::table('offers')
            ->join('stores', 'stores.id', '=', 'offers.store_id')
            ->join('chains', 'chains.id', '=', 'stores.chain_id')
            ->leftJoin('discounts', 'discounts.offer_id', '=', 'offers.id')
            ->whereIn('offers.product_id', $productIds)
            ->where('offers.in_stock', true)
            ->where('offers.last_seen_at', '>=', now()->subDay())
            ->when($cityId, fn ($query) => $query->where('stores.city_id', $cityId))
            ->orderBy('offers.price')
            ->get([
                'offers.product_id',
                'offers.price',
                'offers.old_price',
                'offers.unit_price',
                'offers.stock',
                'offers.in_stock',
                'discounts.discount_percent',
                'discounts.profit',
                'discounts.metadata',
                'stores.id as store_id',
                'stores.latitude',
                'stores.longitude',
                'stores.address',
                'stores.external_id',
                'chains.code as chain_code',
            ])
            ->groupBy('product_id');

        if ($limit !== null) {
            $grouped = $grouped->map(fn ($rows) => $rows->take($limit)->values());
        }

        return $grouped->all();
    }

    private function bestLevelsForProduct(mixed $offers): array
    {
        $bestLevels = [];
        $bestDiscount = 0.0;

        foreach ($offers as $offer) {
            $metadata = $offer->metadata ?? null;
            $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
            $levels = is_array($metadata) && is_array($metadata['levels'] ?? null) ? $metadata['levels'] : [];

            foreach ($levels as $level) {
                $discount = isset($level['discount_percent']) ? (float) $level['discount_percent'] : 0.0;
                if ($discount > $bestDiscount) {
                    $bestDiscount = $discount;
                    $bestLevels = $levels;
                }
            }
        }

        return $bestLevels;
    }

    /** @return array<array{0: float, 1: float}>|null */
    private function parsePolygon(string $polygon): ?array
    {
        if ($polygon === '') {
            return null;
        }

        $decoded = json_decode($polygon, true);
        if (!is_array($decoded)) {
            return null;
        }

        $points = [];
        foreach ($decoded as $point) {
            if (!is_array($point) || count($point) < 2) {
                continue;
            }

            $lat = isset($point[0]) ? (float) $point[0] : null;
            $lng = isset($point[1]) ? (float) $point[1] : null;
            if ($lat === null || $lng === null) {
                continue;
            }

            $points[] = [$lat, $lng];
        }

        return count($points) >= 3 ? $points : null;
    }

    /** @param array<array{0: float, 1: float}> $polygon
     *  @return array<int>
     */
    private function storeIdsWithinPolygon(array $polygon, ?int $cityId, string $chainCode): array
    {
        return Store::query()
            ->when($cityId, fn ($query, $selectedCityId) => $query->where('city_id', $selectedCityId))
            ->when($chainCode !== '', function ($query) use ($chainCode): void {
                $query->whereHas('chain', fn ($chain) => $chain->where('code', $chainCode));
            })
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'latitude', 'longitude'])
            ->filter(fn (Store $store): bool => $this->pointInPolygon((float) $store->latitude, (float) $store->longitude, $polygon))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @param array<array{0: float, 1: float}> $polygon */
    private function pointInPolygon(float $latitude, float $longitude, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$yi, $xi] = $polygon[$i];
            [$yj, $xj] = $polygon[$j];

            $intersects = (($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < (($xj - $xi) * ($latitude - $yi)) / (($yj - $yi) ?: PHP_FLOAT_EPSILON) + $xi);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /** @return array<int> */
    private function categoryWithDescendantIds(int $categoryId): array
    {
        $selected = Category::query()->find($categoryId);
        if (!$selected instanceof Category) {
            return [$categoryId];
        }

        $peerRootIds = Category::query()
            ->where('name', $selected->name)
            ->when($selected->parent_id === null || $selected->level === 0, function ($query): void {
                $query->where(fn ($nested) => $nested->whereNull('parent_id')->orWhere('level', 0));
            }, function ($query) use ($selected): void {
                $query->whereKey($selected->id);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $ids = $peerRootIds ?: [$categoryId];
        $frontier = $ids;

        while ($frontier) {
            $children = Category::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $children = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }
}
