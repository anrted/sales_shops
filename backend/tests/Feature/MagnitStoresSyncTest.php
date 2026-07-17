<?php

namespace Tests\Feature;

use App\Models\Chain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MagnitStoresSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sync_magnit_stores_and_cities_from_real_api_shape(): void
    {
        Chain::query()->create(['code' => 'magnit', 'name' => 'Магнит']);
        config()->set('services.magnit.store_boxes', [
            ['lat1' => 53.8727, 'lon1' => 86.8704, 'lat2' => 53.6473, 'lon2' => 87.2796],
        ]);

        Http::fake([
            'https://magnit.ru/webgate/v1/stores-facade/search/detail' => Http::response([
                'data' => [
                    [
                        'storeType' => 'STORE_TYPE_MM',
                        'externalId' => ['storeCode' => 'm-1'],
                        'address' => '660000, г. Красноярск, ул. Мира, 1',
                        'coordinates' => [
                            'latitude' => 56.0106,
                            'longitude' => 92.8526,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/admin/magnit/stores/sync')
            ->assertOk()
            ->assertJsonPath('item.cities', 1)
            ->assertJsonPath('item.stores', 1);

        $this->assertDatabaseHas('cities', ['name' => 'Красноярск']);
        $this->assertDatabaseHas('stores', [
            'external_id' => 'm-1',
            'type' => 'STORE_TYPE_MM',
            'address' => '660000, г. Красноярск, ул. Мира, 1',
        ]);
    }

    public function test_admin_can_import_magnit_stores_from_yandex_map(): void
    {
        Chain::query()->create(['code' => 'magnit', 'name' => 'Магнит']);

        $this->postJson('/api/admin/magnit/stores/import-yandex', [
            'stores' => [
                [
                    'source_id' => 'ymaps-1',
                    'name' => 'Магнит',
                    'address' => '654018, г. Новокузнецк, Кондомское шоссе, 19',
                    'latitude' => 53.733932,
                    'longitude' => 87.17936,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('item.cities', 1)
            ->assertJsonPath('item.stores', 1);

        $this->assertDatabaseHas('cities', ['name' => 'Новокузнецк']);
        $this->assertDatabaseHas('stores', [
            'external_id' => 'yandex:ymaps-1',
            'name' => 'Магнит',
            'type' => 'yandex-map',
            'address' => '654018, г. Новокузнецк, Кондомское шоссе, 19',
        ]);
    }

    public function test_admin_can_fetch_magnit_stores_by_map_box(): void
    {
        Chain::query()->create(['code' => 'magnit', 'name' => 'Магнит']);

        Http::fake([
            'https://magnit.ru/webgate/v1/stores-facade/search/detail' => Http::response([
                'data' => [
                    [
                        'storeType' => 'STORE_TYPE_MM',
                        'externalId' => ['storeCode' => 'm-map-1'],
                        'address' => '654018, г. Новокузнецк, Кондомское шоссе, 19',
                        'coordinates' => [
                            'latitude' => 53.733932,
                            'longitude' => 87.17936,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/admin/magnit/stores?lat1=53.78&lon1=87.12&lat2=53.73&lon2=87.18')
            ->assertOk()
            ->assertJsonPath('item.stores.0.storeCode', 'm-map-1')
            ->assertJsonPath('item.stores.0.latitude', 53.733932)
            ->assertJsonPath('item.stores.0.longitude', 87.17936);
    }

    public function test_admin_can_import_magnit_api_stores_with_real_store_code(): void
    {
        Chain::query()->create(['code' => 'magnit', 'name' => 'Магнит']);

        $this->postJson('/api/admin/magnit/stores/import', [
            'stores' => [
                [
                    'storeCode' => 'm-real-1',
                    'storeType' => 'STORE_TYPE_MM',
                    'address' => '654018, г. Новокузнецк, Кондомское шоссе, 19',
                    'latitude' => 53.733932,
                    'longitude' => 87.17936,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('item.stores', 1);

        $this->assertDatabaseHas('stores', [
            'external_id' => 'm-real-1',
            'type' => 'STORE_TYPE_MM',
            'address' => '654018, г. Новокузнецк, Кондомское шоссе, 19',
        ]);
    }
}
