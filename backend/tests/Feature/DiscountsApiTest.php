<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Chain;
use App\Models\City;
use App\Models\Discount;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_are_aggregated_by_city_best_price(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Магнит']);
        $city = City::query()->create(['name' => 'Красноярск', 'slug' => 'krasnoyarsk']);
        $category = Category::query()->create(['chain_id' => $chain->id, 'external_id' => 'milk', 'name' => 'Молоко']);
        $product = Product::query()->create([
            'chain_id' => $chain->id,
            'category_id' => $category->id,
            'external_id' => 'p1',
            'name' => 'Молоко 2.5%',
        ]);

        foreach ([120, 99] as $index => $price) {
            $store = Store::query()->create([
                'chain_id' => $chain->id,
                'city_id' => $city->id,
                'external_id' => 's'.$index,
                'address' => 'Улица '.$index,
            ]);
            $offer = Offer::query()->create([
                'product_id' => $product->id,
                'store_id' => $store->id,
                'price' => $price,
                'old_price' => 150,
                'in_stock' => true,
                'last_seen_at' => now(),
            ]);
            Discount::query()->create([
                'offer_id' => $offer->id,
                'discount_percent' => 34,
                'profit' => 150 - $price,
            ]);
        }

        $this->getJson("/api/discounts?city_id={$city->id}&sort=price")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.best_price', 99)
            ->assertJsonPath('items.0.stores_count', 2);
    }

    public function test_stale_offers_are_hidden(): void
    {
        $chain = Chain::query()->create(['code' => 'metro', 'name' => 'Metro']);
        $city = City::query()->create(['name' => 'Москва', 'slug' => 'moscow']);
        $store = Store::query()->create(['chain_id' => $chain->id, 'city_id' => $city->id, 'external_id' => '54']);
        $product = Product::query()->create(['chain_id' => $chain->id, 'external_id' => 'a1', 'name' => 'Сыр']);
        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'price' => 300,
            'old_price' => 500,
            'in_stock' => true,
            'last_seen_at' => now()->subDays(2),
        ]);
        Discount::query()->create(['offer_id' => $offer->id, 'discount_percent' => 40, 'profit' => 200]);

        $this->getJson("/api/discounts?city_id={$city->id}")
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('items', []);
    }

    public function test_parent_category_includes_child_categories(): void
    {
        $chain = Chain::query()->create(['code' => 'metro', 'name' => 'Metro']);
        $city = City::query()->create(['name' => 'Москва', 'slug' => 'moscow']);
        $store = Store::query()->create(['chain_id' => $chain->id, 'city_id' => $city->id, 'external_id' => '54']);
        $parent = Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'frozen',
            'name' => 'Заморозка',
            'level' => 0,
        ]);
        $child = Category::query()->create([
            'chain_id' => $chain->id,
            'parent_id' => $parent->id,
            'external_id' => 'pelmeni',
            'name' => 'Пельмени',
            'level' => 1,
        ]);
        $product = Product::query()->create([
            'chain_id' => $chain->id,
            'category_id' => $child->id,
            'external_id' => 'p1',
            'name' => 'Пельмени домашние',
        ]);
        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'price' => 199,
            'old_price' => 299,
            'in_stock' => true,
            'last_seen_at' => now(),
        ]);
        Discount::query()->create(['offer_id' => $offer->id, 'discount_percent' => 33, 'profit' => 100]);

        $this->getJson("/api/categories?chain=metro")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Заморозка');

        $this->getJson("/api/discounts?city_id={$city->id}&category={$parent->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.name', 'Пельмени домашние');
    }

    public function test_products_can_be_found_by_search_query(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);
        $city = City::query()->create(['name' => 'Novokuznetsk', 'slug' => 'novokuznetsk']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $city->id,
            'external_id' => 'search-store',
        ]);

        $matched = Product::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'tea-1',
            'name' => 'Chai Chernyi',
        ]);
        $other = Product::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'coffee-1',
            'name' => 'Kofe Molotyi',
        ]);

        foreach ([$matched, $other] as $product) {
            $offer = Offer::query()->create([
                'product_id' => $product->id,
                'store_id' => $store->id,
                'price' => 100,
                'old_price' => 150,
                'in_stock' => true,
                'last_seen_at' => now(),
            ]);

            Discount::query()->create([
                'offer_id' => $offer->id,
                'discount_percent' => 33,
                'profit' => 50,
            ]);
        }

        $this->getJson('/api/discounts?q=chai')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.name', 'Chai Chernyi');
    }

    public function test_products_can_be_found_by_cyrillic_search_query_in_sqlite(): void
    {
        $chain = Chain::query()->create(['code' => 'metro', 'name' => 'Metro']);
        $city = City::query()->create(['name' => 'Novokuznetsk', 'slug' => 'novokuznetsk']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $city->id,
            'external_id' => 'metro-search-store',
        ]);

        $matched = Product::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'metro-tea-1',
            'name' => 'Чай черный Metro Chef',
        ]);
        $other = Product::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'metro-coffee-1',
            'name' => 'Кофе молотый Metro Chef',
        ]);

        foreach ([$matched, $other] as $product) {
            $offer = Offer::query()->create([
                'product_id' => $product->id,
                'store_id' => $store->id,
                'price' => 100,
                'old_price' => 150,
                'in_stock' => true,
                'last_seen_at' => now(),
            ]);

            Discount::query()->create([
                'offer_id' => $offer->id,
                'discount_percent' => 33,
                'profit' => 50,
            ]);
        }

        $this->getJson('/api/discounts?q=чай')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.name', 'Чай черный Metro Chef');
    }

    public function test_discount_sort_uses_quantity_level_max_discount(): void
    {
        $chain = Chain::query()->create(['code' => 'metro', 'name' => 'Metro']);
        $city = City::query()->create(['name' => 'Москва', 'slug' => 'moscow']);
        $store = Store::query()->create(['chain_id' => $chain->id, 'city_id' => $city->id, 'external_id' => '54']);

        $bulkProduct = Product::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'bulk',
            'name' => 'Товар с лучшей скидкой от 12 шт',
        ]);
        $bulkOffer = Offer::query()->create([
            'product_id' => $bulkProduct->id,
            'store_id' => $store->id,
            'price' => 90,
            'old_price' => 100,
            'in_stock' => true,
            'last_seen_at' => now(),
        ]);
        Discount::query()->create([
            'offer_id' => $bulkOffer->id,
            'discount_percent' => 35,
            'profit' => 35,
            'metadata' => [
                'base_discount_percent' => 10,
                'levels' => [
                    ['count' => 1, 'price' => 90, 'discount_percent' => 10],
                    ['count' => 12, 'price' => 65, 'discount_percent' => 35],
                ],
            ],
        ]);

        $regularProduct = Product::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'regular',
            'name' => 'Товар с обычной скидкой 30%',
        ]);
        $regularOffer = Offer::query()->create([
            'product_id' => $regularProduct->id,
            'store_id' => $store->id,
            'price' => 70,
            'old_price' => 100,
            'in_stock' => true,
            'last_seen_at' => now(),
        ]);
        Discount::query()->create([
            'offer_id' => $regularOffer->id,
            'discount_percent' => 30,
            'profit' => 30,
        ]);

        $this->getJson("/api/discounts?city_id={$city->id}&sort=discount")
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Товар с лучшей скидкой от 12 шт')
            ->assertJsonPath('items.0.discount_percent', 35)
            ->assertJsonPath('items.0.levels.1.count', 12)
            ->assertJsonPath('items.0.levels.1.discount_percent', 35);
    }

    public function test_product_details_include_store_coordinates_and_stock(): void
    {
        $chain = Chain::query()->create(['code' => 'metro', 'name' => 'Metro']);
        $city = City::query()->create(['name' => 'Moscow', 'slug' => 'moscow']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $city->id,
            'external_id' => '54',
            'address' => 'Lenina 1',
            'latitude' => 55.7522,
            'longitude' => 37.6156,
        ]);
        $product = Product::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'p-map',
            'name' => 'Cheese',
        ]);
        $offer = Offer::query()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'price' => 199,
            'old_price' => 249,
            'stock' => 7,
            'in_stock' => true,
            'last_seen_at' => now(),
        ]);
        Discount::query()->create([
            'offer_id' => $offer->id,
            'discount_percent' => 20,
            'profit' => 50,
        ]);

        $this->getJson("/api/products/{$product->id}?city_id={$city->id}")
            ->assertOk()
            ->assertJsonPath('item.best_price', 199)
            ->assertJsonPath('item.stores_count', 1)
            ->assertJsonPath('item.offers.0.stock', 7)
            ->assertJsonPath('item.offers.0.latitude', 55.7522)
            ->assertJsonPath('item.offers.0.longitude', 37.6156);
    }

    public function test_discounts_can_be_filtered_by_polygon_area(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);
        $city = City::query()->create(['name' => 'Krasnoyarsk', 'slug' => 'krasnoyarsk']);
        $insideStore = Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $city->id,
            'external_id' => 'inside',
            'latitude' => 55.7500,
            'longitude' => 37.6100,
        ]);
        $outsideStore = Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $city->id,
            'external_id' => 'outside',
            'latitude' => 55.8000,
            'longitude' => 37.7000,
        ]);

        $insideProduct = Product::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'inside-product',
            'name' => 'Inside product',
        ]);
        $outsideProduct = Product::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'outside-product',
            'name' => 'Outside product',
        ]);

        foreach ([[$insideProduct, $insideStore], [$outsideProduct, $outsideStore]] as [$product, $store]) {
            $offer = Offer::query()->create([
                'product_id' => $product->id,
                'store_id' => $store->id,
                'price' => 100,
                'old_price' => 120,
                'in_stock' => true,
                'last_seen_at' => now(),
            ]);
            Discount::query()->create([
                'offer_id' => $offer->id,
                'discount_percent' => 20,
                'profit' => 20,
            ]);
        }

        $polygon = json_encode([
            [55.7000, 37.5500],
            [55.7000, 37.6500],
            [55.7800, 37.6500],
            [55.7800, 37.5500],
        ], JSON_THROW_ON_ERROR);

        $this->getJson('/api/discounts?city_id='.$city->id.'&polygon='.urlencode($polygon))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.name', 'Inside product');
    }

    public function test_top_categories_are_deduplicated_by_chain_name_and_include_peer_store_types(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Лента']);
        $city = City::query()->create(['name' => 'Москва', 'slug' => 'moscow']);
        $storeHm = Store::query()->create(['chain_id' => $chain->id, 'city_id' => $city->id, 'external_id' => 'hm']);
        $storeSm = Store::query()->create(['chain_id' => $chain->id, 'city_id' => $city->id, 'external_id' => 'sm']);

        $rootHm = Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'milk-hm',
            'store_type' => 'HM',
            'name' => 'Молочные продукты',
            'level' => 0,
        ]);
        $rootSm = Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => 'milk-sm',
            'store_type' => 'SM',
            'name' => 'Молочные продукты',
            'level' => 0,
        ]);
        $childHm = Category::query()->create([
            'chain_id' => $chain->id,
            'parent_id' => $rootHm->id,
            'external_id' => 'drink-hm',
            'store_type' => 'HM',
            'name' => 'Питьевые йогурты',
            'level' => 1,
        ]);
        $childSm = Category::query()->create([
            'chain_id' => $chain->id,
            'parent_id' => $rootSm->id,
            'external_id' => 'drink-sm',
            'store_type' => 'SM',
            'name' => 'Питьевые йогурты',
            'level' => 1,
        ]);

        $productHm = Product::query()->create([
            'chain_id' => $chain->id,
            'category_id' => $childHm->id,
            'external_id' => 'bio-hm',
            'name' => 'Биойогурт АКТИБИО Манго 3%, без змж, 130г',
        ]);
        $productSm = Product::query()->create([
            'chain_id' => $chain->id,
            'category_id' => $childSm->id,
            'external_id' => 'bio-sm',
            'name' => 'Биойогурт АКТИБИО Манго 3%, без змж, 130г',
        ]);

        $offerHm = Offer::query()->create([
            'product_id' => $productHm->id,
            'store_id' => $storeHm->id,
            'price' => 79,
            'old_price' => 109,
            'in_stock' => true,
            'last_seen_at' => now(),
        ]);
        $offerSm = Offer::query()->create([
            'product_id' => $productSm->id,
            'store_id' => $storeSm->id,
            'price' => 75,
            'old_price' => 105,
            'in_stock' => true,
            'last_seen_at' => now(),
        ]);
        Discount::query()->create(['offer_id' => $offerHm->id, 'discount_percent' => 27.52, 'profit' => 30]);
        Discount::query()->create(['offer_id' => $offerSm->id, 'discount_percent' => 28.57, 'profit' => 30]);

        $categoriesResponse = $this->getJson('/api/categories?chain=lenta&top_only=1')
            ->assertOk();

        $this->assertCount(1, $categoriesResponse->json('items'));
        $categoryId = (int) $categoriesResponse->json('items.0.id');

        $this->getJson("/api/discounts?city_id={$city->id}&category={$categoryId}")
            ->assertOk()
            ->assertJsonPath('total', 2);
    }
    public function test_global_top_category_deduplicates_same_names_across_chains(): void
    {
        $lenta = Chain::query()->create(['code' => 'lenta-2', 'name' => 'Lenta 2']);
        $metro = Chain::query()->create(['code' => 'metro-2', 'name' => 'Metro 2']);
        $city = City::query()->create(['name' => 'Novokuznetsk', 'slug' => 'novokuznetsk-2']);

        $lentaStore = Store::query()->create(['chain_id' => $lenta->id, 'city_id' => $city->id, 'external_id' => 'lenta-auto']);
        $metroStore = Store::query()->create(['chain_id' => $metro->id, 'city_id' => $city->id, 'external_id' => 'metro-auto']);

        $lentaRoot = Category::query()->create([
            'chain_id' => $lenta->id,
            'external_id' => 'auto-lenta',
            'name' => 'Автотовары',
            'level' => 0,
        ]);
        $metroRoot = Category::query()->create([
            'chain_id' => $metro->id,
            'external_id' => 'auto-metro',
            'name' => 'Автотовары',
            'level' => 0,
        ]);

        $lentaChild = Category::query()->create([
            'chain_id' => $lenta->id,
            'parent_id' => $lentaRoot->id,
            'external_id' => 'washer-lenta',
            'name' => 'Омыватель',
            'level' => 1,
        ]);
        $metroChild = Category::query()->create([
            'chain_id' => $metro->id,
            'parent_id' => $metroRoot->id,
            'external_id' => 'washer-metro',
            'name' => 'Омыватель',
            'level' => 1,
        ]);

        $lentaProduct = Product::query()->create([
            'chain_id' => $lenta->id,
            'category_id' => $lentaChild->id,
            'external_id' => 'lp-auto',
            'name' => 'Омыватель зимний Лента',
        ]);
        $metroProduct = Product::query()->create([
            'chain_id' => $metro->id,
            'category_id' => $metroChild->id,
            'external_id' => 'mp-auto',
            'name' => 'Омыватель зимний Metro',
        ]);

        $lentaOffer = Offer::query()->create([
            'product_id' => $lentaProduct->id,
            'store_id' => $lentaStore->id,
            'price' => 120,
            'old_price' => 150,
            'in_stock' => true,
            'last_seen_at' => now(),
        ]);
        $metroOffer = Offer::query()->create([
            'product_id' => $metroProduct->id,
            'store_id' => $metroStore->id,
            'price' => 115,
            'old_price' => 145,
            'in_stock' => true,
            'last_seen_at' => now(),
        ]);

        Discount::query()->create(['offer_id' => $lentaOffer->id, 'discount_percent' => 20, 'profit' => 30]);
        Discount::query()->create(['offer_id' => $metroOffer->id, 'discount_percent' => 20.69, 'profit' => 30]);

        $categoriesResponse = $this->getJson('/api/categories?top_only=1')
            ->assertOk();

        $matchingCategories = collect($categoriesResponse->json('items'))
            ->filter(fn (array $item): bool => ($item['name'] ?? null) === 'Автотовары')
            ->values();

        $this->assertCount(1, $matchingCategories);
        $categoryId = (int) ($matchingCategories[0]['id'] ?? 0);

        $this->getJson("/api/discounts?city_id={$city->id}&category={$categoryId}")
            ->assertOk()
            ->assertJsonPath('total', 2);
    }
}
