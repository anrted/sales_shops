<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cities')
            ->whereIn('slug', ['moscow', 'saint-petersburg', 'krasnoyarsk'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('stores')
                    ->whereColumn('stores.city_id', 'cities.id');
            })
            ->delete();
    }

    public function down(): void
    {
        //
    }
};
