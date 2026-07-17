<?php

namespace Tests\Feature;

use App\Models\Chain;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\StoreProviders\MagnitProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MagnitProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.magnit.goods_request_delay_ms', 0);
        config()->set('services.magnit.goods_use_curl_binary', false);
    }

    public function test_magnit_provider_parses_products_without_legacy_project(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Магнит']);
        config()->set('services.magnit.store_boxes', [
            ['lat1' => 53.8727, 'lon1' => 86.8704, 'lat2' => 53.6473, 'lon2' => 87.2796],
        ]);

        Http::fake([
            'https://magnit.ru/webgate/v1/stores-facade/search/detail' => Http::response([
                'data' => [[
                    'storeType' => 'STORE_TYPE_MM',
                    'externalId' => ['storeCode' => '404463'],
                    'address' => '654018, г. Новокузнецк, ул. Мира, 1',
                    'coordinates' => ['latitude' => 53.75, 'longitude' => 87.1],
                ]],
            ]),
            'https://magnit.ru/catalog*' => Http::response('<script id="__NUXT_DATA__" type="application/json">[null,"47161","Молоко","/catalog/47161",{"key":1,"parentKey":0,"id":1,"name":2,"code":1,"icon":-1,"url":3,"children":0}]</script>'),
            'https://magnit.ru/webgate/v2/goods/search' => Http::response([
                'items' => [[
                    'id' => '8001',
                    'productId' => '8001',
                    'name' => 'Молоко 2.5%',
                    'price' => 7999,
                    'quantity' => 7,
                    'promotion' => [
                        'isPromotion' => true,
                        'discountPercent' => 20,
                        'endDate' => '2026-06-01T23:59:59Z',
                    ],
                    'gallery' => [['url' => 'https://images-foodtech.magnit.ru/product.png']],
                    'seoCode' => 'moloko_25',
                    'storeCode' => '404463',
                ]],
                'pagination' => ['hasMore' => false],
            ]),
        ]);

        $result = app(MagnitProvider::class)->parse($chain);

        $this->assertSame(1, $result->storesCount);
        $this->assertSame(1, $result->productsCount);
        $this->assertSame(1, $result->offersCount);

        $product = Product::query()->where('external_id', '8001')->firstOrFail();
        $this->assertSame('https://images-foodtech.magnit.ru/product.png', $product->image_url);
        $this->assertDatabaseHas('discounts', ['discount_percent' => 20]);
    }

    public function test_magnit_categories_are_saved_per_store_type(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);

        Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '100',
            'type' => 'STORE_TYPE_MM',
            'is_active' => true,
        ]);
        Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '200',
            'type' => 'STORE_TYPE_GM',
            'is_active' => true,
        ]);

        Http::fake([
            'https://magnit.ru/catalog?shopCode=100' => Http::response('<script id="__NUXT_DATA__" type="application/json">[null,"47161","Dairy MM","/catalog/47161","47162","Milk MM","/catalog/47162",{"key":1,"parentKey":0,"id":1,"name":2,"code":1,"icon":-1,"url":3,"children":0},{"key":2,"parentKey":1,"id":4,"name":5,"code":4,"icon":-1,"url":6,"children":0}]</script>'),
            'https://magnit.ru/catalog?shopCode=200' => Http::response('<script id="__NUXT_DATA__" type="application/json">[null,"47161","Dairy GM","/catalog/47161",{"key":1,"parentKey":0,"id":1,"name":2,"code":1,"icon":-1,"url":3,"children":0}]</script>'),
        ]);

        $result = app(MagnitProvider::class)->syncCategoriesForStoreTypes($chain);

        $this->assertSame(2, $result['store_types']);
        $this->assertSame(3, $result['categories']);
        $this->assertSame(2, Category::query()->where('external_id', '47161')->count());
        $this->assertDatabaseHas('categories', [
            'chain_id' => $chain->id,
            'store_type' => 'STORE_TYPE_MM',
            'external_id' => '47161',
            'name' => 'Dairy MM',
            'level' => 0,
        ]);
        $parent = Category::query()
            ->where('store_type', 'STORE_TYPE_MM')
            ->where('external_id', '47161')
            ->firstOrFail();

        $this->assertDatabaseHas('categories', [
            'chain_id' => $chain->id,
            'parent_id' => $parent->id,
            'store_type' => 'STORE_TYPE_MM',
            'external_id' => '47162',
            'name' => 'Milk MM',
            'level' => 1,
        ]);
        $this->assertDatabaseHas('categories', [
            'chain_id' => $chain->id,
            'store_type' => 'STORE_TYPE_GM',
            'external_id' => '47161',
            'name' => 'Dairy GM',
        ]);
    }

    public function test_magnit_provider_uses_stores_and_categories_from_database_when_available(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '404463',
            'type' => 'STORE_TYPE_MM',
            'is_active' => true,
        ]);
        $category = Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '47161',
            'store_type' => 'STORE_TYPE_MM',
            'name' => 'Stored dairy',
            'slug' => 'stored-dairy',
            'level' => 0,
        ]);

        Http::fake([
            'https://magnit.ru/webgate/v2/goods/search' => Http::response([
                'items' => [[
                    'id' => '8001',
                    'productId' => '8001',
                    'name' => 'РњРѕР»РѕРєРѕ 2.5%',
                    'price' => 7999,
                    'quantity' => 7,
                    'promotion' => [
                        'isPromotion' => true,
                        'discountPercent' => 20,
                        'endDate' => '2026-06-01T23:59:59Z',
                    ],
                    'gallery' => [['url' => 'https://images-foodtech.magnit.ru/product.png']],
                    'seoCode' => 'moloko_25',
                    'storeCode' => '404463',
                ]],
                'pagination' => ['hasMore' => false],
            ]),
        ]);

        $result = app(MagnitProvider::class)->parse($chain, null, $store->id);

        $this->assertSame(1, $result->storesCount);
        $this->assertSame(1, $result->productsCount);
        $this->assertSame(1, $result->offersCount);
        $this->assertDatabaseHas('products', [
            'external_id' => '8001',
            'category_id' => $category->id,
        ]);
        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/stores-facade/search/detail'));
        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/catalog'));
    }

    public function test_magnit_goods_request_matches_minimal_header_set(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '404463',
            'type' => 'STORE_TYPE_MM',
            'is_active' => true,
        ]);
        Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '47161',
            'store_type' => 'STORE_TYPE_MM',
            'name' => 'Stored dairy',
            'level' => 0,
        ]);

        config()->set('services.magnit.device_id', '26f6df22-86e8-4574-b48a-ec8617b41f42');
        config()->set('services.magnit.goods_store_type', '1');
        config()->set('services.magnit.accept_language', 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7');
        config()->set('services.magnit.baggage', 'test-baggage');

        Http::fake([
            'https://magnit.ru/webgate/v2/goods/search' => Http::response([
                'items' => [[
                    'id' => '8001',
                    'productId' => '8001',
                    'name' => 'Milk',
                    'price' => 7999,
                    'quantity' => 7,
                    'promotion' => [
                        'isPromotion' => true,
                        'discountPercent' => 20,
                    ],
                    'gallery' => [['url' => 'https://images-foodtech.magnit.ru/product.png']],
                ]],
                'pagination' => ['hasMore' => false],
            ]),
        ]);

        app(MagnitProvider::class)->parse($chain, null, $store->id);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if ($request->url() !== 'https://magnit.ru/webgate/v2/goods/search') {
                return false;
            }

            return $request['storeType'] === '1'
                && $request['storeCode'] === '404463'
                && ($request->header('x-device-id')[0] ?? null) === '26f6df22-86e8-4574-b48a-ec8617b41f42'
                && ($request->header('Accept-Language')[0] ?? null) === 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7'
                && ($request->header('baggage')[0] ?? null) === 'test-baggage'
                && $request->header('Cookie') === []
                && $request->header('Origin') === []
                && $request->header('Referer') === [];
        });
    }

    public function test_magnit_provider_skips_waf_blocked_category_and_continues_parse(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '404463',
            'type' => 'STORE_TYPE_MM',
            'is_active' => true,
        ]);
        Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '47161',
            'store_type' => 'STORE_TYPE_MM',
            'name' => 'Stored dairy',
            'level' => 0,
        ]);

        Http::fake([
            'https://magnit.ru/webgate/v2/goods/search' => Http::response(
                "Forbidden\nTransaction ID: test-waf-id\n",
                403,
            ),
        ]);

        $result = app(MagnitProvider::class)->parse($chain, null, $store->id);

        $this->assertSame(1, $result->storesCount);
        $this->assertSame(0, $result->productsCount);
        $this->assertSame(0, $result->offersCount);
    }

    public function test_magnit_city_parse_uses_only_active_stores_and_prioritizes_gm_before_mm(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);
        $cityId = 77;

        $gmStore = Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $cityId,
            'external_id' => 'gm-store',
            'type' => 'STORE_TYPE_GM',
            'is_active' => true,
        ]);
        $mmStore = Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $cityId,
            'external_id' => 'mm-store',
            'type' => 'STORE_TYPE_MM',
            'is_active' => true,
        ]);
        Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $cityId,
            'external_id' => 'inactive-store',
            'type' => 'STORE_TYPE_GM',
            'is_active' => false,
        ]);

        Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '9001',
            'store_type' => 'STORE_TYPE_GM',
            'name' => 'GM category',
            'level' => 0,
        ]);
        Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '9002',
            'store_type' => 'STORE_TYPE_MM',
            'name' => 'MM category',
            'level' => 0,
        ]);

        config()->set('services.magnit.store_delay_ms', 0);
        config()->set('services.magnit.category_delay_ms', 0);
        config()->set('services.magnit.page_delay_min_ms', 0);
        config()->set('services.magnit.page_delay_max_ms', 0);

        Http::fake([
            'https://magnit.ru/webgate/v2/goods/search' => Http::response([
                'items' => [[
                    'id' => '8001',
                    'productId' => '8001',
                    'name' => 'Milk',
                    'price' => 7999,
                    'quantity' => 7,
                    'promotion' => [
                        'isPromotion' => true,
                        'discountPercent' => 20,
                    ],
                    'gallery' => [['url' => 'https://images-foodtech.magnit.ru/product.png']],
                ]],
                'pagination' => ['hasMore' => false],
            ]),
        ]);

        $result = app(MagnitProvider::class)->parse($chain, $cityId);

        $this->assertSame(2, $result->storesCount);

        $requests = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://magnit.ru/webgate/v2/goods/search')
            ->values();

        $this->assertCount(2, $requests);
        $this->assertSame('gm-store', $requests[0]['storeCode']);
        $this->assertSame([9001], $requests[0]['categories']);
        $this->assertSame('mm-store', $requests[1]['storeCode']);
        $this->assertSame([9002], $requests[1]['categories']);
        $this->assertNotSame('inactive-store', $requests[0]['storeCode']);
        $this->assertNotSame('inactive-store', $requests[1]['storeCode']);
    }

    public function test_magnit_parse_requests_only_top_level_categories_from_database(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '404463',
            'type' => 'STORE_TYPE_MM',
            'is_active' => true,
        ]);

        $parent = Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '47161',
            'store_type' => 'STORE_TYPE_MM',
            'name' => 'Parent',
            'level' => 0,
        ]);
        Category::query()->create([
            'chain_id' => $chain->id,
            'parent_id' => $parent->id,
            'external_id' => '47162',
            'store_type' => 'STORE_TYPE_MM',
            'name' => 'Child',
            'level' => 1,
        ]);

        Http::fake([
            'https://magnit.ru/webgate/v2/goods/search' => Http::response([
                'items' => [],
                'pagination' => ['hasMore' => false],
            ]),
        ]);

        app(MagnitProvider::class)->parse($chain, null, $store->id);

        $requests = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://magnit.ru/webgate/v2/goods/search')
            ->values();

        $this->assertCount(1, $requests);
        $this->assertSame([47161], $requests[0]['categories']);
    }

    public function test_magnit_pagination_stops_by_total_count_when_has_more_flag_is_still_true(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '994224',
            'type' => 'STORE_TYPE_GM',
            'is_active' => true,
        ]);

        Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '112444',
            'store_type' => 'STORE_TYPE_GM',
            'name' => 'Grill',
            'level' => 0,
        ]);

        Http::fake([
            'https://magnit.ru/webgate/v2/goods/search' => function (\Illuminate\Http\Client\Request $request) {
                $offset = (int) data_get($request->data(), 'pagination.offset', 0);
                $limit = (int) data_get($request->data(), 'pagination.limit', 50);
                $totalCount = 120;
                $remaining = max(0, $totalCount - $offset);
                $itemsCount = min($limit, $remaining);

                $items = [];
                for ($index = 0; $index < $itemsCount; $index++) {
                    $id = (string) ($offset + $index + 1);
                    $items[] = [
                        'id' => $id,
                        'productId' => $id,
                        'name' => 'Item '.$id,
                        'price' => 9999,
                        'quantity' => 5,
                        'promotion' => [
                            'isPromotion' => true,
                            'discountPercent' => 20,
                        ],
                        'gallery' => [['url' => 'https://images-foodtech.magnit.ru/product.png']],
                    ];
                }

                return Http::response([
                    'items' => $items,
                    'pagination' => [
                        'hasMore' => true,
                        'limit' => $limit,
                        'nextOffset' => null,
                        'offset' => $offset,
                        'totalCount' => $totalCount,
                    ],
                ]);
            },
        ]);

        $result = app(MagnitProvider::class)->parse($chain, null, $store->id);

        $this->assertSame(1, $result->storesCount);
        $this->assertSame(120, $result->productsCount);
        $this->assertSame(120, $result->offersCount);

        $requests = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://magnit.ru/webgate/v2/goods/search')
            ->values();

        $this->assertCount(3, $requests);
        $this->assertSame(0, data_get($requests[0]->data(), 'pagination.offset'));
        $this->assertSame(50, data_get($requests[1]->data(), 'pagination.offset'));
        $this->assertSame(100, data_get($requests[2]->data(), 'pagination.offset'));
    }

    public function test_magnit_limits_category_to_top_discounted_150_items(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '994224',
            'type' => 'STORE_TYPE_GM',
            'is_active' => true,
        ]);

        Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '112444',
            'store_type' => 'STORE_TYPE_GM',
            'name' => 'Grill',
            'level' => 0,
        ]);

        config()->set('services.magnit.max_items_per_category', 150);

        Http::fake([
            'https://magnit.ru/webgate/v2/goods/search' => function (\Illuminate\Http\Client\Request $request) {
                $offset = (int) data_get($request->data(), 'pagination.offset', 0);
                $limit = (int) data_get($request->data(), 'pagination.limit', 50);

                $items = [];
                for ($index = 0; $index < $limit; $index++) {
                    $id = (string) ($offset + $index + 1);
                    $items[] = [
                        'id' => $id,
                        'productId' => $id,
                        'name' => 'Item '.$id,
                        'price' => 9999,
                        'quantity' => 5,
                        'promotion' => [
                            'isPromotion' => true,
                            'discountPercent' => 20,
                        ],
                        'gallery' => [['url' => 'https://images-foodtech.magnit.ru/product.png']],
                    ];
                }

                return Http::response([
                    'items' => $items,
                    'pagination' => [
                        'hasMore' => true,
                        'limit' => $limit,
                        'nextOffset' => null,
                        'offset' => $offset,
                        'totalCount' => 236,
                    ],
                ]);
            },
        ]);

        $result = app(MagnitProvider::class)->parse($chain, null, $store->id);

        $this->assertSame(150, $result->productsCount);
        $this->assertSame(150, $result->offersCount);

        $requests = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://magnit.ru/webgate/v2/goods/search')
            ->values();

        $this->assertCount(3, $requests);
        $this->assertSame(0, data_get($requests[0]->data(), 'pagination.offset'));
        $this->assertSame(50, data_get($requests[1]->data(), 'pagination.offset'));
        $this->assertSame(100, data_get($requests[2]->data(), 'pagination.offset'));
    }

    public function test_magnit_category_sync_forces_refresh_from_site_for_admin_import(): void
    {
        $chain = Chain::query()->create(['code' => 'magnit', 'name' => 'Magnit']);

        Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '100',
            'type' => 'STORE_TYPE_MM',
            'is_active' => true,
        ]);

        Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '47161',
            'store_type' => 'STORE_TYPE_MM',
            'name' => 'Old cached name',
            'level' => 0,
        ]);

        Http::fake([
            'https://magnit.ru/catalog?shopCode=100' => Http::response('<script id="__NUXT_DATA__" type="application/json">[null,"47161","Fresh name from site","/catalog/47161",{"key":1,"parentKey":0,"id":1,"name":2,"code":1,"icon":-1,"url":3,"children":0}]</script>'),
        ]);

        $result = app(MagnitProvider::class)->syncCategoriesForStoreTypes($chain, true);

        $this->assertSame(1, $result['store_types']);
        $this->assertSame(1, $result['categories']);
        $this->assertDatabaseHas('categories', [
            'chain_id' => $chain->id,
            'store_type' => 'STORE_TYPE_MM',
            'external_id' => '47161',
            'name' => 'Fresh name from site',
        ]);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://magnit.ru/catalog?shopCode=100');
    }
}
