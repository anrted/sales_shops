<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunStoreProviderParse;
use App\Models\Chain;
use App\Models\ParseRun;
use App\Services\QueueWorkerKickstarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class AdminParseRunController extends Controller
{
    private const CANCEL_REQUEST_STALE_SECONDS = 120;

    public function index(): JsonResponse
    {
        $this->finalizeStaleCancelledRuns();

        return response()->json([
            'items' => ParseRun::query()
                ->with(['chain:id,code,name'])
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    public function store(Request $request, QueueWorkerKickstarter $queueWorker): JsonResponse
    {
        $data = $request->validate([
            'chain' => ['required', 'string', 'exists:chains,code'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        $chain = Chain::query()->where('code', $data['chain'])->first();

        $run = ParseRun::query()->create([
            'chain_id' => $chain?->id,
            'city_id' => $data['city_id'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'status' => ParseRun::STATUS_QUEUED,
            'current_step' => 'queued',
            'heartbeat_at' => now(),
        ]);

        if ($queueWorker->canRunDirectly()) {
            $queueWorker->runParseRun($run->id);
        } else {
            RunStoreProviderParse::dispatch($data['chain'], $data['city_id'] ?? null, $data['store_id'] ?? null, $run->id);
            $queueWorker->kick();
        }

        return response()->json(['item' => $run], 202);
    }

    public function cancel(ParseRun $parseRun): JsonResponse
    {
        if ($parseRun->status === ParseRun::STATUS_QUEUED) {
            $parseRun->update([
                'status' => ParseRun::STATUS_CANCELLED,
                'current_step' => 'cancelled before start',
                'heartbeat_at' => now(),
                'finished_at' => now(),
            ]);
        } elseif ($parseRun->status === ParseRun::STATUS_RUNNING) {
            $parseRun->update([
                'status' => ParseRun::STATUS_CANCEL_REQUESTED,
                'current_step' => 'cancel requested',
                'heartbeat_at' => now(),
            ]);
        }

        $this->finalizeStaleCancelledRuns();

        return response()->json(['item' => $parseRun->fresh(['chain:id,code,name'])]);
    }

    public function destroy(ParseRun $parseRun): Response|JsonResponse
    {
        if (in_array($parseRun->status, [
            ParseRun::STATUS_QUEUED,
            ParseRun::STATUS_RUNNING,
            ParseRun::STATUS_CANCEL_REQUESTED,
        ], true)) {
            return response()->json([
                'message' => 'Активный запуск нельзя удалить. Сначала остановите его или дождитесь завершения.',
            ], 422);
        }

        $parseRun->delete();

        return response()->noContent();
    }

    private function finalizeStaleCancelledRuns(): void
    {
        ParseRun::query()
            ->where('status', ParseRun::STATUS_CANCEL_REQUESTED)
            ->where(function ($query): void {
                $query->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<=', now()->subSeconds(self::CANCEL_REQUEST_STALE_SECONDS));
            })
            ->update([
                'status' => ParseRun::STATUS_CANCELLED,
                'current_step' => 'cancelled after stale worker',
                'finished_at' => now(),
            ]);
    }
}
