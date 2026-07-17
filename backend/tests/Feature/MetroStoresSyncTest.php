<?php

namespace Tests\Feature;

use App\Models\Chain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetroStoresSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sync_metro_stores_and_cities(): void
    {
        Chain::query()->create(['code' => 'metro', 'name' => 'Metro']);

        Http::fake([
            'https://api.metro-cc.ru/api/v1/*/tradecenters' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 55,
                        'store_id' => 54,
                        'name' => 'Ул. Кондомское ш., д. 19',
                        'address' => '654018, г. Новокузнецк, Кондомское шоссе, 19',
                        'city' => 'Новокузнецк',
                        'coordinates' => [
                            'latitude' => 53.733932,
                            'longitude' => 87.17936,
                        ],
                    ],
                ],
                'errors' => [],
            ]),
        ]);

        $this->postJson('/api/admin/metro/stores/sync')
            ->assertOk()
            ->assertJsonPath('item.cities', 1)
            ->assertJsonPath('item.stores', 1);

        $this->assertDatabaseHas('cities', ['name' => 'Новокузнецк']);
        $this->assertDatabaseHas('stores', [
            'external_id' => '54',
            'name' => 'Ул. Кондомское ш., д. 19',
            'address' => '654018, г. Новокузнецк, Кондомское шоссе, 19',
        ]);
    }
}
