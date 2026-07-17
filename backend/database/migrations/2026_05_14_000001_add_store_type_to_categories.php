<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('store_type')->nullable()->after('external_id');
            $table->dropUnique('categories_chain_id_external_id_unique');
            $table->unique(['chain_id', 'store_type', 'external_id']);
            $table->index(['chain_id', 'store_type']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['chain_id', 'store_type']);
            $table->dropUnique('categories_chain_id_store_type_external_id_unique');
            $table->dropColumn('store_type');
            $table->unique(['chain_id', 'external_id']);
        });
    }
};
