<?php

namespace Database\Seeders;

use App\Models\Chain;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'magnit', 'name' => 'Магнит'],
            ['code' => 'metro', 'name' => 'Metro'],
            ['code' => 'lenta', 'name' => 'Лента'],
        ] as $chain) {
            Chain::query()->updateOrCreate(['code' => $chain['code']], $chain + ['is_active' => true]);
        }
    }
}
