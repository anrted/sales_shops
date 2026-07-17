<?php

use App\Jobs\RunStoreProviderParse;
use App\Models\Chain;
use App\Models\ParseRun;
use App\Models\Store;
use App\Services\CityResolver;
use App\Services\LentaSessionRefresher;
use Illuminate\Support\Facades\Artisan;

Artisan::command('discounts:parse {--chain=} {--city_id=} {--store_id=}', function (): int {
    $chainCode = $this->option('chain');
    $cityId = $this->option('city_id') ? (int) $this->option('city_id') : null;
    $storeId = $this->option('store_id') ? (int) $this->option('store_id') : null;

    $chains = $chainCode
        ? Chain::query()->where('code', $chainCode)->get()
        : Chain::query()->where('is_active', true)->get();

    foreach ($chains as $chain) {
        RunStoreProviderParse::dispatch($chain->code, $cityId, $storeId);
        $this->info("Queued parser for {$chain->code}");
    }

    return self::SUCCESS;
})->purpose('Queue discount parser jobs.');

Artisan::command('stores:resolve-cities {--chain=}', function (CityResolver $resolver): int {
    $chainCode = $this->option('chain');
    $updated = 0;
    $skipped = 0;

    Store::query()
        ->with('chain:id,code')
        ->whereNull('city_id')
        ->whereNotNull('address')
        ->when($chainCode, fn ($query) => $query->whereHas('chain', fn ($chain) => $chain->where('code', $chainCode)))
        ->orderBy('id')
        ->chunkById(200, function ($stores) use ($resolver, &$updated, &$skipped): void {
            foreach ($stores as $store) {
                $city = $resolver->resolve(null, $store->address);

                if (!$city) {
                    $skipped++;
                    continue;
                }

                $store->update(['city_id' => $city->id]);
                $updated++;
            }
        });

    $this->info("Resolved cities: {$updated}. Skipped: {$skipped}.");

    return self::SUCCESS;
})->purpose('Resolve missing store city_id values from store addresses.');

Artisan::command('discounts:run-parse-run {parse_run_id}', function (): int {
    $parseRunId = (int) $this->argument('parse_run_id');
    $run = ParseRun::query()->with('chain:id,code')->findOrFail($parseRunId);
    $chainCode = $run->chain?->code;

    if (! is_string($chainCode) || $chainCode === '') {
        $this->error("Parse run {$parseRunId} does not have a valid chain.");

        return self::FAILURE;
    }

    $job = new RunStoreProviderParse($chainCode, $run->city_id, $run->store_id, $run->id);
    app()->call([$job, 'handle']);

    return self::SUCCESS;
})->purpose('Run one parse run in a fresh PHP process without a long-lived queue worker.');

Artisan::command('lenta:refresh-session {--headed} {--timeout=90}', function (LentaSessionRefresher $refresher): int {
    $result = $refresher->refresh(
        (bool) $this->option('headed'),
        max(30, (int) $this->option('timeout')),
    );

    $this->info('Lenta session refreshed successfully.');
    $this->line('Domain: '.($result['settings']['default_domain'] ?? '-'));
    $this->line('Cookies: '.($result['cookie_count'] ?? 0));
    $this->line('Updated at: '.($result['status']['updated_at'] ?? '-'));

    return self::SUCCESS;
})->purpose('Refresh Lenta anti-bot cookies and session settings through a browser run.');
