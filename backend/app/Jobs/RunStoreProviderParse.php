<?php

namespace App\Jobs;

use App\Exceptions\ParseRunCancelled;
use App\Models\Chain;
use App\Models\ParseRun;
use App\Services\ParseRunProgress;
use App\Services\StoreProviders\StoreProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunStoreProviderParse implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public readonly string $chainCode,
        public readonly ?int $cityId = null,
        public readonly ?int $storeId = null,
        public readonly ?int $parseRunId = null,
    ) {
    }

    public function handle(StoreProviderManager $providers, ParseRunProgress $progress): void
    {
        $chain = Chain::query()->where('code', $this->chainCode)->firstOrFail();
        $run = $this->parseRunId
            ? ParseRun::query()->findOrFail($this->parseRunId)
            : ParseRun::query()->create([
                'chain_id' => $chain->id,
                'city_id' => $this->cityId,
                'store_id' => $this->storeId,
            ]);

        if (in_array($run->status, [ParseRun::STATUS_CANCEL_REQUESTED, ParseRun::STATUS_CANCELLED], true)) {
            $run->update([
                'status' => ParseRun::STATUS_CANCELLED,
                'current_step' => 'cancelled before start',
                'heartbeat_at' => now(),
                'finished_at' => now(),
            ]);

            return;
        }

        $run->update([
            'status' => ParseRun::STATUS_RUNNING,
            'current_step' => 'starting',
            'heartbeat_at' => now(),
            'started_at' => now(),
        ]);
        $progress->useRun($run);

        try {
            $progress->update('preparing provider');
            $result = $providers->forCode($chain->code)->parse($chain, $this->cityId, $this->storeId);

            $run->update([
                'status' => ParseRun::STATUS_SUCCESS,
                'current_step' => 'completed',
                'heartbeat_at' => now(),
                'stores_count' => $result->storesCount,
                'products_count' => $result->productsCount,
                'offers_count' => $result->offersCount,
                'finished_at' => now(),
            ]);
        } catch (ParseRunCancelled) {
            $run->update([
                'status' => ParseRun::STATUS_CANCELLED,
                'current_step' => 'cancelled by user',
                'heartbeat_at' => now(),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => ParseRun::STATUS_ERROR,
                'current_step' => 'error',
                'heartbeat_at' => now(),
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }
}
