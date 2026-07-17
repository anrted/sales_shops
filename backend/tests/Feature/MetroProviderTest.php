<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Chain;
use App\Models\Store;
use App\Services\StoreProviders\MetroProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetroProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_metro_provider_uses_stores_and_categories_from_database_when_available(): void
    {
        $chain = Chain::query()->create(['code' => 'metro', 'name' => 'Metro']);
        $store = Store::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '54',
            'name' => 'Stored Metro',
            'is_active' => true,
        ]);
        $category = Category::query()->create([
            'chain_id' => $chain->id,
            'external_id' => '100',
            'name' => 'Stored category',
            'slug' => 'stored-category',
            'level' => 0,
        ]);

        Http::fake([
            'https://api.metro-cc.ru/products-api/graph*' => Http::response([
                'data' => [
                    'search' => [
                        'products' => [
                            'products' => [
                                [
                                    'slug' => 'milk',
                                    'name' => 'Молоко Metro',
                                    'article' => '8001',
                                    'stocks' => [[
                                        'value' => 9,
                                        'prices_per_unit' => [
                                            'price' => 79.99,
                                            'old_price' => 99.99,
                                            'discount' => 20,
                                        ],
                                        'prices' => [
                                            'price' => 79.99,
                                            'old_price' => 99.99,
                                            'discount' => 20,
                                            'levels' => [],
                                        ],
                                    ]],
                                    'category_id' => '100',
                                    'packing' => ['size' => 1],
                                    'images' => ['https://img.metro/product.png'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(MetroProvider::class)->parse($chain, null, $store->id);

        $this->assertSame(1, $result->storesCount);
        $this->assertSame(1, $result->productsCount);
        $this->assertSame(1, $result->offersCount);
        $this->assertDatabaseHas('products', [
            'external_id' => '8001',
            'category_id' => $category->id,
        ]);
        Http::assertNotSent(function (Request $request): bool {
            if (!in_array($request->url(), [
                'https://api.metro-cc.ru/products-api/graphql',
                'https://api.metro-cc.ru/products-api/graph',
            ], true)) {
                return false;
            }

            $body = json_decode($request->body(), true);

            return is_array($body)
                && str_contains((string) ($body['query'] ?? ''), 'categories(size:');
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tradecenters'));
    }
}
