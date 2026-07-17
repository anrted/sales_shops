<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Chain;
use App\Models\City;
use App\Models\Discount;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Store;
use App\Services\StoreProviders\LentaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class LentaProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_lenta_provider_parses_pickup_catalog_and_syncs_stores(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Лента']);
        config()->set('services.lenta.page_size', 2);
        config()->set('services.lenta.device_id', '79ef2c1b-09b1-130f-8209-0765f7f2bee1');
        config()->set('services.lenta.user_session_id', '1c1d3e60-30c1-074b-6918-84bed023f434');
        config()->set('services.lenta.utk_marketing_group_token', 'DFDAA274E34A45A01BEC7B1D7B34F846');
        config()->set('services.lenta.utk_sss_token', 'B67E6FE15272BCE01F028AA97A75B883');
        config()->set('services.lenta.qrator_ssid', '1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5');

        Http::fake([
            'https://lenta.com/api/rest/sessionGet' => Http::response([
                'Body' => ['SessionToken' => 'session-token'],
            ]),
            'https://lenta.com/api-gateway/v1/region/list' => Http::response([
                'regions' => [
                    ['id' => 147, 'slug' => 'nsk'],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/stores/pickup/search' => Http::response([
                'items' => [[
                    'id' => 3543,
                    'title' => 'ТК233',
                    'alias' => '0233',
                    'marketType' => 'HM',
                    'addressFull' => 'Новокузнецк, Хлебозаводская ул., 19',
                    'addressShort' => 'Новокузнецк, Хлебозаводская ул., 19',
                    'regionId' => 147,
                    'coordinates' => ['latitude' => 53.77642, 'longitude' => 87.11659],
                ]],
            ]),
            'https://lenta.com/api-gateway/v1/delivery/mode/set' => Http::response([
                'sessionToken' => 'session-token',
                'storeId' => 3543,
                'type' => 'pickup',
            ]),
            'https://lenta.com/api-gateway/v1/catalog/categories*' => Http::response([
                'categories' => [
                    [
                        'id' => 24781,
                        'name' => 'Дача, спорт, туризм',
                        'slug' => 'dacha-sport-turizm',
                        'parentId' => 0,
                        'level' => 1,
                        'hasChildren' => true,
                    ],
                    [
                        'id' => 24783,
                        'name' => 'Мебель',
                        'slug' => 'mebel',
                        'parentId' => 24781,
                        'level' => 2,
                        'hasChildren' => false,
                    ],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/catalog/items' => Http::sequence()
                ->push([
                    'items' => [
                        $this->lentaItem(
                            id: 742238,
                            categoryId: 24783,
                            price: 9999,
                            oldPrice: 48420,
                            stock: 23,
                            slug: 'kovrik',
                            name: 'Коврик для пересадки',
                            image: 'https://cdn.api.lenta.com/product-1.png',
                            unit: 'шт',
                            unitQuantity: 1,
                        ),
                        $this->lentaItem(
                            id: 742239,
                            categoryId: 24783,
                            price: 5000,
                            oldPrice: 7000,
                            stock: 5,
                            slug: 'stol',
                            name: 'Стол складной',
                            image: 'https://cdn.api.lenta.com/product-2.png',
                            unit: 'шт',
                            unitQuantity: 1,
                        ),
                    ],
                ])
                ->push([
                    'items' => [
                        $this->lentaItem(
                            id: 742240,
                            categoryId: 24783,
                            price: 0,
                            oldPrice: 7000,
                            stock: 5,
                            slug: 'invalid-price',
                            name: 'Товар без цены',
                            image: 'https://cdn.api.lenta.com/product-3.png',
                            unit: 'шт',
                            unitQuantity: 1,
                        ),
                        $this->lentaItem(
                            id: 742241,
                            categoryId: 24783,
                            price: 7000,
                            oldPrice: 7000,
                            stock: 5,
                            slug: 'no-discount',
                            name: 'Товар без скидки',
                            image: 'https://cdn.api.lenta.com/product-4.png',
                            unit: 'шт',
                            unitQuantity: 1,
                        ),
                    ],
                ])
                ->push(['items' => []]),
        ]);

        $result = app(LentaProvider::class)->parse($chain);

        $this->assertSame(1, $result->storesCount);
        $this->assertSame(2, $result->productsCount);
        $this->assertSame(2, $result->offersCount);

        $store = Store::query()->where('external_id', '3543')->firstOrFail();
        $this->assertSame('HM', $store->type);

        $parent = Category::query()->where('external_id', '24781')->firstOrFail();
        $child = Category::query()->where('external_id', '24783')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_id);

        $product = Product::query()->where('external_id', '742238')->firstOrFail();
        $this->assertSame('https://cdn.api.lenta.com/product-1.png', $product->image_url);
        $this->assertSame('шт', $product->unit);
        $this->assertSame('1.000', number_format((float) $product->unit_size, 3, '.', ''));

        $offer = Offer::query()->where('product_id', $product->id)->firstOrFail();
        $discount = Discount::query()->where('offer_id', $offer->id)->firstOrFail();
        $this->assertSame('79.35', number_format((float) $discount->discount_percent, 2, '.', ''));
        $this->assertDatabaseMissing('products', ['external_id' => '742240']);
        $this->assertDatabaseMissing('products', ['external_id' => '742241']);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://lenta.com/api/rest/sessionGet') {
                return false;
            }

            parse_str($request->body(), $form);
            $payload = json_decode($form['request'] ?? '', true);

            return $request->hasHeader('content-type', 'application/x-www-form-urlencoded')
                && is_array($payload)
                && Arr::get($payload, 'Head.Method') === 'sessionGet'
                && Arr::get($payload, 'Head.Client') === 'angular_web_0.0.2'
                && Arr::get($payload, 'Head.Domain') === config('services.lenta.default_domain')
                && Arr::get($payload, 'Head.DeviceId') === '79ef2c1b-09b1-130f-8209-0765f7f2bee1'
                && str_contains(($request->header('Cookie')[0] ?? ''), 'Utk_MrkGrpTkn=DFDAA274E34A45A01BEC7B1D7B34F846')
                && str_contains(($request->header('Cookie')[0] ?? ''), 'Utk_SssTkn=B67E6FE15272BCE01F028AA97A75B883')
                && str_contains(($request->header('Cookie')[0] ?? ''), 'qrator_ssid=1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5')
                && Arr::get($payload, 'Body') === [];
        });

        Http::assertSentCount(8);
    }

    public function test_lenta_provider_uses_existing_store_filter_and_stops_on_short_page(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Лента']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '3543',
            'name' => 'ТК233',
            'type' => 'HM',
            'address' => 'Новокузнецк, Хлебозаводская ул., 19',
            'is_active' => true,
        ]);

        config()->set('services.lenta.page_size', 600);
        config()->set('services.lenta.device_id', '79ef2c1b-09b1-130f-8209-0765f7f2bee1');
        config()->set('services.lenta.user_session_id', '1c1d3e60-30c1-074b-6918-84bed023f434');
        config()->set('services.lenta.utk_marketing_group_token', 'DFDAA274E34A45A01BEC7B1D7B34F846');
        config()->set('services.lenta.utk_sss_token', 'B67E6FE15272BCE01F028AA97A75B883');
        config()->set('services.lenta.qrator_ssid', '1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5');

        Http::fake([
            'https://lenta.com/api/rest/sessionGet' => Http::response([
                'Body' => ['SessionToken' => 'session-token'],
            ]),
            'https://lenta.com/api-gateway/v1/region/list' => Http::response([
                'regions' => [
                    ['id' => 147, 'slug' => 'nsk'],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/stores/pickup/search' => Http::response([
                'items' => [[
                    'id' => 3543,
                    'title' => 'ТК233',
                    'alias' => '0233',
                    'marketType' => 'HM',
                    'addressFull' => 'Новокузнецк, Хлебозаводская ул., 19',
                    'addressShort' => 'Новокузнецк, Хлебозаводская ул., 19',
                    'regionId' => 147,
                    'coordinates' => ['latitude' => 53.77642, 'longitude' => 87.11659],
                ]],
            ]),
            'https://lenta.com/api-gateway/v1/delivery/mode/set' => Http::response(['type' => 'pickup']),
            'https://lenta.com/api-gateway/v1/catalog/categories*' => Http::response([
                'categories' => [[
                    'id' => 24783,
                    'name' => 'Мебель',
                    'parentId' => 0,
                    'level' => 1,
                    'hasChildren' => false,
                ]],
            ]),
            'https://lenta.com/api-gateway/v1/catalog/items' => Http::response([
                'items' => [
                    $this->lentaItem(
                        id: 742250,
                        categoryId: 24783,
                        price: 15000,
                        oldPrice: 20000,
                        stock: 3,
                        slug: 'kreslo',
                        name: 'Кресло садовое',
                        image: 'https://cdn.api.lenta.com/product-5.png',
                        unit: 'шт',
                        unitQuantity: 1,
                    ),
                ],
            ]),
        ]);

        $result = app(LentaProvider::class)->parse($chain, null, $store->id);

        $this->assertSame(1, $result->storesCount);
        $this->assertSame(1, $result->productsCount);
        $this->assertSame(1, $result->offersCount);
        Http::assertSentCount(4);
    }

    public function test_lenta_provider_uses_stores_and_categories_from_database_when_available(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Lenta']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '3543',
            'name' => 'HM-1',
            'type' => 'HM',
            'address' => 'Addr HM 1',
            'is_active' => true,
        ]);
        $category = Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '24783',
            'store_type' => 'HM',
            'name' => 'Stored category',
            'slug' => 'stored-category',
            'level' => 0,
        ]);

        config()->set('services.lenta.page_size', 50);
        config()->set('services.lenta.device_id', '79ef2c1b-09b1-130f-8209-0765f7f2bee1');
        config()->set('services.lenta.user_session_id', '1c1d3e60-30c1-074b-6918-84bed023f434');
        config()->set('services.lenta.utk_marketing_group_token', 'DFDAA274E34A45A01BEC7B1D7B34F846');
        config()->set('services.lenta.utk_sss_token', 'B67E6FE15272BCE01F028AA97A75B883');
        config()->set('services.lenta.qrator_ssid', '1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5');
        config()->set('services.lenta.default_domain', 'nsk');

        Http::fake([
            'https://lenta.com/api/rest/sessionGet' => Http::response([
                'Body' => ['SessionToken' => 'session-token'],
            ]),
            'https://lenta.com/api-gateway/v1/delivery/mode/set' => Http::response(['type' => 'pickup']),
            'https://lenta.com/api-gateway/v1/catalog/items' => Http::response([
                'items' => [
                    $this->lentaItem(
                        id: 742250,
                        categoryId: 24783,
                        price: 15000,
                        oldPrice: 20000,
                        stock: 3,
                        slug: 'stored-category-product',
                        name: 'Stored Category Product',
                        image: 'https://cdn.api.lenta.com/product-5.png',
                        unit: 'С€С‚',
                        unitQuantity: 1,
                    ),
                ],
            ]),
        ]);

        $result = app(LentaProvider::class)->parse($chain, null, $store->id);

        $this->assertSame(1, $result->storesCount);
        $this->assertSame(1, $result->productsCount);
        $this->assertSame(1, $result->offersCount);
        $this->assertDatabaseHas('products', [
            'external_id' => '742250',
            'category_id' => $category->id,
        ]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/region/list'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/stores/pickup/search'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/catalog/categories'));
        Http::assertSentCount(3);
    }

    public function test_lenta_provider_skips_category_parsing_for_unsupported_store_types(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Р›РµРЅС‚Р°']);

        config()->set('services.lenta.device_id', '79ef2c1b-09b1-130f-8209-0765f7f2bee1');
        config()->set('services.lenta.user_session_id', '1c1d3e60-30c1-074b-6918-84bed023f434');
        config()->set('services.lenta.utk_marketing_group_token', 'DFDAA274E34A45A01BEC7B1D7B34F846');
        config()->set('services.lenta.utk_sss_token', 'B67E6FE15272BCE01F028AA97A75B883');
        config()->set('services.lenta.qrator_ssid', '1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5');

        Http::fake([
            'https://lenta.com/api/rest/sessionGet' => Http::response([
                'Body' => ['SessionToken' => 'session-token'],
            ]),
            'https://lenta.com/api-gateway/v1/region/list' => Http::response([
                'regions' => [
                    ['id' => 147, 'slug' => 'nsk'],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/stores/pickup/search' => Http::response([
                'items' => [[
                    'id' => 9999,
                    'title' => 'РўРљ9999',
                    'alias' => '9999',
                    'marketType' => 'MINI',
                    'addressFull' => 'РќРѕРІРѕРєСѓР·РЅРµС†Рє, СѓР». РўРµСЃС‚РѕРІР°СЏ, 1',
                    'addressShort' => 'РќРѕРІРѕРєСѓР·РЅРµС†Рє, СѓР». РўРµСЃС‚РѕРІР°СЏ, 1',
                    'regionId' => 147,
                    'coordinates' => ['latitude' => 53.77642, 'longitude' => 87.11659],
                ]],
            ]),
        ]);

        try {
            app(LentaProvider::class)->parse($chain);
            $this->fail('Expected unsupported-store parse to fail fast.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Lenta parse requires a selected city or a specific store.', $exception->getMessage());
        }

        $this->assertDatabaseHas('stores', [
            'external_id' => '9999',
            'type' => 'MINI',
        ]);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('products', 0);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/catalog/categories'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/catalog/items'));
        Http::assertSentCount(3);
    }

    public function test_lenta_categories_can_be_synced_separately_per_supported_store_type(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Р›РµРЅС‚Р°']);

        config()->set('services.lenta.device_id', '79ef2c1b-09b1-130f-8209-0765f7f2bee1');
        config()->set('services.lenta.user_session_id', '1c1d3e60-30c1-074b-6918-84bed023f434');
        config()->set('services.lenta.utk_marketing_group_token', 'DFDAA274E34A45A01BEC7B1D7B34F846');
        config()->set('services.lenta.utk_sss_token', 'B67E6FE15272BCE01F028AA97A75B883');
        config()->set('services.lenta.qrator_ssid', '1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5');

        Http::fake([
            'https://lenta.com/api/rest/sessionGet' => Http::response([
                'Body' => ['SessionToken' => 'session-token'],
            ]),
            'https://lenta.com/api-gateway/v1/region/list' => Http::response([
                'regions' => [
                    ['id' => 147, 'slug' => 'nsk'],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/stores/pickup/search' => Http::response([
                'items' => [
                    [
                        'id' => 3543,
                        'title' => 'РўРљ233',
                        'alias' => '0233',
                        'marketType' => 'HM',
                        'addressFull' => 'РќРѕРІРѕРєСѓР·РЅРµС†Рє, СѓР». РҐР»РµР±РѕР·Р°РІРѕРґСЃРєР°СЏ, 19',
                        'addressShort' => 'РќРѕРІРѕРєСѓР·РЅРµС†Рє, СѓР». РҐР»РµР±РѕР·Р°РІРѕРґСЃРєР°СЏ, 19',
                        'regionId' => 147,
                        'coordinates' => ['latitude' => 53.77642, 'longitude' => 87.11659],
                    ],
                    [
                        'id' => 4544,
                        'title' => 'РўРљ4544',
                        'alias' => '04544',
                        'marketType' => 'SM',
                        'addressFull' => 'РќРѕРІРѕРєСѓР·РЅРµС†Рє, СѓР». РўРµСЃС‚РѕРІР°СЏ, 7',
                        'addressShort' => 'РќРѕРІРѕРєСѓР·РЅРµС†Рє, СѓР». РўРµСЃС‚РѕРІР°СЏ, 7',
                        'regionId' => 147,
                        'coordinates' => ['latitude' => 53.77643, 'longitude' => 87.11660],
                    ],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/delivery/mode/set' => Http::response(['type' => 'pickup']),
            'https://lenta.com/api-gateway/v1/catalog/categories*' => Http::sequence()
                ->push([
                    'categories' => [[
                        'id' => 24781,
                        'name' => 'HM РљР°С‚РµРіРѕСЂРёСЏ',
                        'parentId' => 0,
                        'level' => 1,
                        'hasChildren' => false,
                    ]],
                ])
                ->push([
                    'categories' => [
                        [
                            'id' => 24781,
                            'name' => 'SM РљР°С‚РµРіРѕСЂРёСЏ',
                            'parentId' => 0,
                            'level' => 1,
                            'hasChildren' => true,
                        ],
                        [
                            'id' => 24782,
                            'name' => 'SM РџРѕРґРєР°С‚РµРіРѕСЂРёСЏ',
                            'parentId' => 24781,
                            'level' => 2,
                            'hasChildren' => false,
                        ],
                    ],
                ]),
        ]);

        $result = app(LentaProvider::class)->syncCategoriesForStoreTypes($chain);

        $this->assertSame(2, $result['store_types']);
        $this->assertSame(3, $result['categories']);
        $this->assertDatabaseHas('categories', [
            'chain_id' => $chain->id,
            'external_id' => '24781',
            'store_type' => 'HM',
            'name' => 'HM РљР°С‚РµРіРѕСЂРёСЏ',
        ]);
        $this->assertDatabaseHas('categories', [
            'chain_id' => $chain->id,
            'external_id' => '24781',
            'store_type' => 'SM',
            'name' => 'SM РљР°С‚РµРіРѕСЂРёСЏ',
        ]);
        $this->assertDatabaseHas('categories', [
            'chain_id' => $chain->id,
            'external_id' => '24782',
            'store_type' => 'SM',
            'name' => 'SM РџРѕРґРєР°С‚РµРіРѕСЂРёСЏ',
        ]);

        $smChild = Category::query()
            ->where('chain_id', $chain->id)
            ->where('store_type', 'SM')
            ->where('external_id', '24782')
            ->firstOrFail();
        $smParent = Category::query()
            ->where('chain_id', $chain->id)
            ->where('store_type', 'SM')
            ->where('external_id', '24781')
            ->firstOrFail();
        $this->assertSame($smParent->id, $smChild->parent_id);
    }

    public function test_lenta_parser_fetches_categories_once_per_store_type(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Р›РµРЅС‚Р°']);

        Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '3543',
            'name' => 'HM-1',
            'type' => 'HM',
            'address' => 'Addr HM 1',
            'is_active' => true,
        ]);
        Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '3544',
            'name' => 'HM-2',
            'type' => 'HM',
            'address' => 'Addr HM 2',
            'is_active' => true,
        ]);
        Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '4544',
            'name' => 'SM-1',
            'type' => 'SM',
            'address' => 'Addr SM 1',
            'is_active' => true,
        ]);

        config()->set('services.lenta.page_size', 50);
        config()->set('services.lenta.device_id', '79ef2c1b-09b1-130f-8209-0765f7f2bee1');
        config()->set('services.lenta.user_session_id', '1c1d3e60-30c1-074b-6918-84bed023f434');
        config()->set('services.lenta.utk_marketing_group_token', 'DFDAA274E34A45A01BEC7B1D7B34F846');
        config()->set('services.lenta.utk_sss_token', 'B67E6FE15272BCE01F028AA97A75B883');
        config()->set('services.lenta.qrator_ssid', '1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5');

        Http::fake([
            'https://lenta.com/api/rest/sessionGet' => Http::response([
                'Body' => ['SessionToken' => 'session-token'],
            ]),
            'https://lenta.com/api-gateway/v1/region/list' => Http::response([
                'regions' => [
                    ['id' => 147, 'slug' => 'nsk'],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/stores/pickup/search' => Http::response([
                'items' => [
                    [
                        'id' => 3543,
                        'title' => 'HM-1',
                        'alias' => '03543',
                        'marketType' => 'HM',
                        'addressFull' => 'Addr HM 1',
                        'addressShort' => 'Addr HM 1',
                        'regionId' => 147,
                        'coordinates' => ['latitude' => 53.77642, 'longitude' => 87.11659],
                    ],
                    [
                        'id' => 3544,
                        'title' => 'HM-2',
                        'alias' => '03544',
                        'marketType' => 'HM',
                        'addressFull' => 'Addr HM 2',
                        'addressShort' => 'Addr HM 2',
                        'regionId' => 147,
                        'coordinates' => ['latitude' => 53.77643, 'longitude' => 87.11660],
                    ],
                    [
                        'id' => 4544,
                        'title' => 'SM-1',
                        'alias' => '04544',
                        'marketType' => 'SM',
                        'addressFull' => 'Addr SM 1',
                        'addressShort' => 'Addr SM 1',
                        'regionId' => 147,
                        'coordinates' => ['latitude' => 53.77644, 'longitude' => 87.11661],
                    ],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/delivery/mode/set' => Http::response(['type' => 'pickup']),
            'https://lenta.com/api-gateway/v1/catalog/categories*' => Http::sequence()
                ->push([
                    'categories' => [[
                        'id' => 24781,
                        'name' => 'HM Category',
                        'parentId' => 0,
                        'level' => 1,
                        'hasChildren' => false,
                    ]],
                ])
                ->push([
                    'categories' => [[
                        'id' => 34781,
                        'name' => 'SM Category',
                        'parentId' => 0,
                        'level' => 1,
                        'hasChildren' => false,
                    ]],
                ]),
            'https://lenta.com/api-gateway/v1/catalog/items' => Http::response(['items' => []]),
        ]);

        $result = app(LentaProvider::class)->parse($chain);

        $this->assertSame(3, $result->storesCount);
        Http::assertSentCount(9);
        $categoryRequests = collect(Http::recorded())
            ->filter(fn (array $record): bool => str_contains($record[0]->url(), '/catalog/categories'))
            ->count();
        $this->assertSame(2, $categoryRequests);
    }

    public function test_lenta_provider_fetches_items_by_root_categories_only(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Р›РµРЅС‚Р°']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '3543',
            'name' => 'РўРљ233',
            'type' => 'HM',
            'address' => 'РќРѕРІРѕРєСѓР·РЅРµС†Рє, РҐР»РµР±РѕР·Р°РІРѕРґСЃРєР°СЏ СѓР»., 19',
            'is_active' => true,
        ]);

        config()->set('services.lenta.page_size', 50);
        config()->set('services.lenta.device_id', '79ef2c1b-09b1-130f-8209-0765f7f2bee1');
        config()->set('services.lenta.user_session_id', '1c1d3e60-30c1-074b-6918-84bed023f434');
        config()->set('services.lenta.utk_marketing_group_token', 'DFDAA274E34A45A01BEC7B1D7B34F846');
        config()->set('services.lenta.utk_sss_token', 'B67E6FE15272BCE01F028AA97A75B883');
        config()->set('services.lenta.qrator_ssid', '1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5');

        Http::fake([
            'https://lenta.com/api/rest/sessionGet' => Http::response([
                'Body' => ['SessionToken' => 'session-token'],
            ]),
            'https://lenta.com/api-gateway/v1/region/list' => Http::response([
                'regions' => [
                    ['id' => 147, 'slug' => 'nsk'],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/stores/pickup/search' => Http::response([
                'items' => [[
                    'id' => 3543,
                    'title' => 'РўРљ233',
                    'alias' => '0233',
                    'marketType' => 'HM',
                    'addressFull' => 'РќРѕРІРѕРєСѓР·РЅРµС†Рє, РҐР»РµР±РѕР·Р°РІРѕРґСЃРєР°СЏ СѓР»., 19',
                    'addressShort' => 'РќРѕРІРѕРєСѓР·РЅРµС†Рє, РҐР»РµР±РѕР·Р°РІРѕРґСЃРєР°СЏ СѓР»., 19',
                    'regionId' => 147,
                    'coordinates' => ['latitude' => 53.77642, 'longitude' => 87.11659],
                ]],
            ]),
            'https://lenta.com/api-gateway/v1/delivery/mode/set' => Http::response(['type' => 'pickup']),
            'https://lenta.com/api-gateway/v1/catalog/categories*' => Http::response([
                'categories' => [
                    [
                        'id' => 24781,
                        'name' => 'Р”Р°С‡Р°, СЃРїРѕСЂС‚, С‚СѓСЂРёР·Рј',
                        'parentId' => 0,
                        'level' => 1,
                        'hasChildren' => true,
                    ],
                    [
                        'id' => 24783,
                        'name' => 'РњРµР±РµР»СЊ',
                        'parentId' => 24781,
                        'level' => 2,
                        'hasChildren' => false,
                    ],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/catalog/items' => Http::response([
                'items' => [
                    $this->lentaItem(
                        id: 742238,
                        categoryId: 24783,
                        price: 9999,
                        oldPrice: 48420,
                        stock: 23,
                        slug: 'kovrik',
                        name: 'РљРѕРІСЂРёРє РґР»СЏ РїРµСЂРµСЃР°РґРєРё',
                        image: 'https://cdn.api.lenta.com/product-1.png',
                        unit: 'С€С‚',
                        unitQuantity: 1,
                    ),
                ],
            ]),
        ]);

        app(LentaProvider::class)->parse($chain, null, $store->id);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://lenta.com/api-gateway/v1/catalog/items') {
                return false;
            }

            $payload = json_decode($request->body(), true);

            return is_array($payload)
                && str_contains((string) (($request->header('referer')[0] ?? '')), '/catalog/')
                && ($payload['categoryId'] ?? null) === 24781;
        });
    }

    public function test_lenta_provider_assigns_root_category_when_item_category_is_missing(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Р›РµРЅС‚Р°']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '3543',
            'name' => 'HM-1',
            'type' => 'HM',
            'address' => 'Addr HM 1',
            'is_active' => true,
        ]);

        config()->set('services.lenta.page_size', 50);
        config()->set('services.lenta.device_id', '79ef2c1b-09b1-130f-8209-0765f7f2bee1');
        config()->set('services.lenta.user_session_id', '1c1d3e60-30c1-074b-6918-84bed023f434');
        config()->set('services.lenta.utk_marketing_group_token', 'DFDAA274E34A45A01BEC7B1D7B34F846');
        config()->set('services.lenta.utk_sss_token', 'B67E6FE15272BCE01F028AA97A75B883');
        config()->set('services.lenta.qrator_ssid', '1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5');

        Http::fake([
            'https://lenta.com/api/rest/sessionGet' => Http::response([
                'Body' => ['SessionToken' => 'session-token'],
            ]),
            'https://lenta.com/api-gateway/v1/region/list' => Http::response([
                'regions' => [
                    ['id' => 147, 'slug' => 'nsk'],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/stores/pickup/search' => Http::response([
                'items' => [[
                    'id' => 3543,
                    'title' => 'HM-1',
                    'alias' => '03543',
                    'marketType' => 'HM',
                    'addressFull' => 'Addr HM 1',
                    'addressShort' => 'Addr HM 1',
                    'regionId' => 147,
                    'coordinates' => ['latitude' => 53.77642, 'longitude' => 87.11659],
                ]],
            ]),
            'https://lenta.com/api-gateway/v1/delivery/mode/set' => Http::response(['type' => 'pickup']),
            'https://lenta.com/api-gateway/v1/catalog/categories*' => Http::response([
                'categories' => [
                    [
                        'id' => 24781,
                        'name' => 'Молочные продукты',
                        'parentId' => 0,
                        'level' => 1,
                        'hasChildren' => true,
                    ],
                    [
                        'id' => 24783,
                        'name' => 'Питьевые йогурты',
                        'parentId' => 24781,
                        'level' => 2,
                        'hasChildren' => false,
                    ],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/catalog/items' => Http::response([
                'items' => [
                    $this->lentaItemWithoutCategory(
                        id: 742299,
                        price: 7900,
                        oldPrice: 10900,
                        stock: 6,
                        slug: 'bio-yogurt',
                        name: 'Биойогурт АКТИБИО Манго 3%, без змж, 130г',
                        image: 'https://cdn.api.lenta.com/product-bio.png',
                        unit: 'шт',
                        unitQuantity: 1,
                    ),
                ],
            ]),
        ]);

        app(LentaProvider::class)->parse($chain, null, $store->id);

        $root = Category::query()->where('external_id', '24781')->firstOrFail();
        $product = Product::query()->where('external_id', '742299')->firstOrFail();

        $this->assertSame($root->id, $product->category_id);
    }

    public function test_lenta_provider_parses_only_selected_city_stores(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Lenta']);
        $targetCity = City::query()->create(['name' => 'Novokuznetsk', 'slug' => 'novokuznetsk']);
        $otherCity = City::query()->create(['name' => 'Moscow', 'slug' => 'moscow']);

        $targetStore = Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $targetCity->id,
            'external_id' => '3543',
            'name' => 'HM-NK',
            'type' => 'HM',
            'address' => 'Addr HM NK',
            'is_active' => true,
        ]);
        $otherStore = Store::query()->create([
            'chain_id' => $chain->id,
            'city_id' => $otherCity->id,
            'external_id' => '4544',
            'name' => 'HM-MSK',
            'type' => 'HM',
            'address' => 'Addr HM MSK',
            'is_active' => true,
        ]);

        config()->set('services.lenta.page_size', 50);
        config()->set('services.lenta.product_batch_size', 50);
        config()->set('services.lenta.device_id', '79ef2c1b-09b1-130f-8209-0765f7f2bee1');
        config()->set('services.lenta.user_session_id', '1c1d3e60-30c1-074b-6918-84bed023f434');
        config()->set('services.lenta.utk_marketing_group_token', 'DFDAA274E34A45A01BEC7B1D7B34F846');
        config()->set('services.lenta.utk_sss_token', 'B67E6FE15272BCE01F028AA97A75B883');
        config()->set('services.lenta.qrator_ssid', '1778753930.922.PzAVAs8Sju0IffqS-q5f6kgmmo3jekaj3tmaopkvo2llltuu5');

        Http::fake([
            'https://lenta.com/api/rest/sessionGet' => Http::response([
                'Body' => ['SessionToken' => 'session-token'],
            ]),
            'https://lenta.com/api-gateway/v1/region/list' => Http::response([
                'regions' => [
                    ['id' => 147, 'slug' => 'nsk'],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/stores/pickup/search' => Http::response([
                'items' => [
                    [
                        'id' => 3543,
                        'title' => 'HM-NK',
                        'alias' => '03543',
                        'marketType' => 'HM',
                        'addressFull' => 'Addr HM NK',
                        'addressShort' => 'Addr HM NK',
                        'regionId' => 147,
                        'coordinates' => ['latitude' => 53.77642, 'longitude' => 87.11659],
                    ],
                    [
                        'id' => 4544,
                        'title' => 'HM-MSK',
                        'alias' => '04544',
                        'marketType' => 'HM',
                        'addressFull' => 'Addr HM MSK',
                        'addressShort' => 'Addr HM MSK',
                        'regionId' => 147,
                        'coordinates' => ['latitude' => 55.7558, 'longitude' => 37.6173],
                    ],
                ],
            ]),
            'https://lenta.com/api-gateway/v1/delivery/mode/set' => Http::response(['type' => 'pickup']),
            'https://lenta.com/api-gateway/v1/catalog/categories*' => Http::response([
                'categories' => [[
                    'id' => 24783,
                    'name' => 'Root',
                    'parentId' => 0,
                    'level' => 1,
                    'hasChildren' => false,
                ]],
            ]),
            'https://lenta.com/api-gateway/v1/catalog/items' => Http::response([
                'items' => [
                    $this->lentaItem(
                        id: 742250,
                        categoryId: 24783,
                        price: 15000,
                        oldPrice: 20000,
                        stock: 3,
                        slug: 'city-selected-product',
                        name: 'City Selected Product',
                        image: 'https://cdn.api.lenta.com/product-5.png',
                        unit: 'шт',
                        unitQuantity: 1,
                    ),
                ],
            ]),
        ]);

        $result = app(LentaProvider::class)->parse($chain, $targetCity->id);

        $this->assertSame(1, $result->storesCount);
        $this->assertSame(1, $result->productsCount);
        $this->assertSame(1, $result->offersCount);
        $this->assertDatabaseHas('offers', ['store_id' => $targetStore->id]);
        $this->assertDatabaseMissing('offers', ['store_id' => $otherStore->id]);
    }

    private function lentaItem(
        int $id,
        int $categoryId,
        int $price,
        int $oldPrice,
        int $stock,
        string $slug,
        string $name,
        string $image,
        string $unit,
        int $unitQuantity,
    ): array {
        return [
            'id' => $id,
            'category' => ['id' => $categoryId],
            'name' => $name,
            'slug' => $slug,
            'count' => $stock,
            'storeId' => 233,
            'images' => [[
                'original' => $image,
                'large' => $image,
                'medium' => $image,
            ]],
            'prices' => [
                'price' => $price,
                'priceRegular' => $oldPrice,
                'costRegular' => $oldPrice,
            ],
            'units' => [
                'itemUnit' => $unit,
                'itemUnitQuantity' => $unitQuantity,
                'saleUnit' => $unit,
                'saleUnitQuantity' => $unitQuantity,
            ],
            'badges' => [
                'discount' => [['title' => '-10%']],
            ],
            'quantityDiscount' => [],
            'quantityDiscountPromo' => [],
        ];
    }

    private function lentaItemWithoutCategory(
        int $id,
        int $price,
        int $oldPrice,
        int $stock,
        string $slug,
        string $name,
        string $image,
        string $unit,
        int $unitQuantity,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'count' => $stock,
            'storeId' => 233,
            'images' => [[
                'original' => $image,
                'large' => $image,
                'medium' => $image,
            ]],
            'prices' => [
                'price' => $price,
                'priceRegular' => $oldPrice,
                'costRegular' => $oldPrice,
            ],
            'units' => [
                'itemUnit' => $unit,
                'itemUnitQuantity' => $unitQuantity,
                'saleUnit' => $unit,
                'saleUnitQuantity' => $unitQuantity,
            ],
            'badges' => [
                'discount' => [['title' => '-10%']],
            ],
            'quantityDiscount' => [],
            'quantityDiscountPromo' => [],
        ];
    }
}
