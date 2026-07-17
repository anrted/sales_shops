<?php

namespace App\Services\StoreProviders;

use App\Models\Category;
use App\Models\Chain;
use App\Models\Discount;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Store;
use App\Services\CityResolver;
use Illuminate\Support\Str;

class ProviderPersister
{
    public function __construct(private readonly CityResolver $cityResolver)
    {
    }

    public function upsertStore(Chain $chain, array $data): Store
    {
        $city = isset($data['city_id'])
            ? null
            : $this->cityResolver->resolve($data['city'] ?? null, $data['address'] ?? null);
        $cityId = $data['city_id'] ?? $city?->id;

        return Store::query()->updateOrCreate(
            ['chain_id' => $chain->id, 'external_id' => (string) $data['external_id']],
            [
                'city_id' => $cityId,
                'name' => $data['name'] ?? null,
                'type' => $data['type'] ?? null,
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'is_active' => true,
                'last_seen_at' => now(),
            ],
        );
    }

    public function upsertCategory(Chain $chain, array $data): Category
    {
        $identity = [
            'chain_id' => $chain->id,
            'external_id' => (string) $data['external_id'],
        ];

        if (array_key_exists('store_type', $data)) {
            $identity['store_type'] = $data['store_type'];
        }

        return Category::query()->updateOrCreate(
            $identity,
            [
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'parent_id' => $data['parent_id'] ?? null,
                'store_type' => $data['store_type'] ?? null,
                'level' => $data['level'] ?? 0,
            ],
        );
    }

    public function upsertProduct(Chain $chain, array $data): Product
    {
        return Product::query()->updateOrCreate(
            ['chain_id' => $chain->id, 'external_id' => (string) $data['external_id']],
            [
                'category_id' => $data['category_id'] ?? null,
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'image_url' => $data['image_url'] ?? null,
                'unit' => $data['unit'] ?? null,
                'unit_size' => $data['unit_size'] ?? null,
                'raw_payload' => $data['raw_payload'] ?? null,
            ],
        );
    }

    public function upsertOffer(Product $product, Store $store, array $data): Offer
    {
        $offer = Offer::query()->updateOrCreate(
            ['product_id' => $product->id, 'store_id' => $store->id],
            [
                'price' => $data['price'],
                'old_price' => $data['old_price'] ?? null,
                'unit_price' => $data['unit_price'] ?? null,
                'stock' => $data['stock'] ?? null,
                'in_stock' => $data['in_stock'] ?? true,
                'last_seen_at' => $data['last_seen_at'] ?? now(),
            ],
        );

        $oldPrice = isset($data['old_price']) ? (float) $data['old_price'] : null;
        $price = (float) $data['price'];
        $profit = $oldPrice && $oldPrice > $price ? round($oldPrice - $price, 2) : 0.0;
        $percent = $oldPrice && $oldPrice > 0 ? round(($profit / $oldPrice) * 100, 2) : (float) ($data['discount_percent'] ?? 0);
        $profit = isset($data['profit']) ? (float) $data['profit'] : $profit;
        $percent = isset($data['discount_percent']) ? (float) $data['discount_percent'] : $percent;

        Discount::query()->updateOrCreate(
            ['offer_id' => $offer->id],
            [
                'discount_percent' => max(0, $percent),
                'profit' => max(0, $profit),
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ],
        );

        return $offer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, Product>
     */
    public function upsertProductsBatch(Chain $chain, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $now = now();
        $externalIds = [];
        $payload = [];

        foreach ($rows as $row) {
            $externalId = (string) ($row['external_id'] ?? '');
            if ($externalId === '') {
                continue;
            }

            $externalIds[] = $externalId;
            $payload[] = [
                'chain_id' => $chain->id,
                'external_id' => $externalId,
                'category_id' => $row['category_id'] ?? null,
                'name' => $row['name'],
                'slug' => $row['slug'] ?? Str::slug($row['name']),
                'image_url' => $row['image_url'] ?? null,
                'unit' => $row['unit'] ?? null,
                'unit_size' => $row['unit_size'] ?? null,
                'raw_payload' => array_key_exists('raw_payload', $row) ? $this->encodeJson($row['raw_payload']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($payload === []) {
            return [];
        }

        Product::query()->upsert(
            $payload,
            ['chain_id', 'external_id'],
            ['category_id', 'name', 'slug', 'image_url', 'unit', 'unit_size', 'raw_payload', 'updated_at'],
        );

        return Product::query()
            ->where('chain_id', $chain->id)
            ->whereIn('external_id', $externalIds)
            ->get()
            ->keyBy(fn (Product $product): string => (string) $product->external_id)
            ->all();
    }

    /**
     * @param  array<int, array{product:Product, store:Store, data:array<string, mixed>}>  $rows
     * @return array<string, Offer>
     */
    public function upsertOffersBatch(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $now = now();
        $payload = [];
        $productIds = [];
        $storeIds = [];

        foreach ($rows as $row) {
            $product = $row['product'];
            $store = $row['store'];
            $data = $row['data'];

            $productIds[] = $product->id;
            $storeIds[] = $store->id;
            $payload[] = [
                'product_id' => $product->id,
                'store_id' => $store->id,
                'price' => $data['price'],
                'old_price' => $data['old_price'] ?? null,
                'unit_price' => $data['unit_price'] ?? null,
                'stock' => $data['stock'] ?? null,
                'in_stock' => $data['in_stock'] ?? true,
                'last_seen_at' => $data['last_seen_at'] ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Offer::query()->upsert(
            $payload,
            ['product_id', 'store_id'],
            ['price', 'old_price', 'unit_price', 'stock', 'in_stock', 'last_seen_at', 'updated_at'],
        );

        return Offer::query()
            ->whereIn('product_id', array_values(array_unique($productIds)))
            ->whereIn('store_id', array_values(array_unique($storeIds)))
            ->get()
            ->keyBy(fn (Offer $offer): string => $offer->product_id.'|'.$offer->store_id)
            ->all();
    }

    /**
     * @param  array<int, array{offer:Offer, data:array<string, mixed>}>  $rows
     */
    public function upsertDiscountsBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        $payload = [];

        foreach ($rows as $row) {
            $offer = $row['offer'];
            $data = $row['data'];

            $oldPrice = isset($data['old_price']) ? (float) $data['old_price'] : null;
            $price = (float) $data['price'];
            $profit = $oldPrice && $oldPrice > $price ? round($oldPrice - $price, 2) : 0.0;
            $percent = $oldPrice && $oldPrice > 0 ? round(($profit / $oldPrice) * 100, 2) : (float) ($data['discount_percent'] ?? 0);
            $profit = isset($data['profit']) ? (float) $data['profit'] : $profit;
            $percent = isset($data['discount_percent']) ? (float) $data['discount_percent'] : $percent;

            $payload[] = [
                'offer_id' => $offer->id,
                'discount_percent' => max(0, $percent),
                'profit' => max(0, $profit),
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'metadata' => array_key_exists('metadata', $data) ? $this->encodeJson($data['metadata']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Discount::query()->upsert(
            $payload,
            ['offer_id'],
            ['discount_percent', 'profit', 'starts_at', 'ends_at', 'metadata', 'updated_at'],
        );
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

}
