<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chains', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['chain_id', 'external_id']);
            $table->index(['city_id', 'chain_id']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chain_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->integer('level')->default(0);
            $table->timestamps();
            $table->unique(['chain_id', 'external_id']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('image_url')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_size', 10, 3)->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->timestamps();
            $table->unique(['chain_id', 'external_id']);
            $table->index('name');
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->decimal('old_price', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->integer('stock')->nullable();
            $table->boolean('in_stock')->default(true);
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['product_id', 'store_id']);
            $table->index(['store_id', 'last_seen_at']);
        });

        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->decimal('discount_percent', 8, 2)->default(0);
            $table->decimal('profit', 12, 2)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unique('offer_id');
            $table->index(['discount_percent', 'profit']);
        });

        Schema::create('parse_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chain_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued');
            $table->unsignedInteger('stores_count')->default(0);
            $table->unsignedInteger('products_count')->default(0);
            $table->unsignedInteger('offers_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX products_name_search_idx ON products USING gin (to_tsvector('russian', coalesce(name, '')))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('parse_runs');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('chains');
    }
};
