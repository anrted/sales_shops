<?php

namespace Tests\Feature;

use App\Jobs\RunStoreProviderParse;
use App\Models\Chain;
use App\Models\ParseRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ParseRunsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_parse_run(): void
    {
        Queue::fake();
        Chain::query()->create(['code' => 'lenta', 'name' => 'Lenta']);

        $this->postJson('/api/admin/parse-runs', ['chain' => 'lenta'])
            ->assertAccepted()
            ->assertJsonPath('item.status', 'queued')
            ->assertJsonPath('item.current_step', 'queued');

        Queue::assertPushed(RunStoreProviderParse::class);
        $this->assertDatabaseHas('parse_runs', [
            'status' => 'queued',
            'current_step' => 'queued',
        ]);
    }

    public function test_admin_can_cancel_queued_parse_run(): void
    {
        $chain = Chain::query()->create(['code' => 'metro', 'name' => 'Metro']);
        $run = ParseRun::query()->create([
            'chain_id' => $chain->id,
            'status' => ParseRun::STATUS_QUEUED,
        ]);

        $this->postJson("/api/admin/parse-runs/{$run->id}/cancel")
            ->assertOk()
            ->assertJsonPath('item.status', ParseRun::STATUS_CANCELLED)
            ->assertJsonPath('item.current_step', 'cancelled before start');

        $this->assertDatabaseHas('parse_runs', [
            'id' => $run->id,
            'status' => ParseRun::STATUS_CANCELLED,
            'current_step' => 'cancelled before start',
        ]);
    }

    public function test_index_finalizes_stale_cancel_requested_parse_run(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Lenta']);
        $run = ParseRun::query()->create([
            'chain_id' => $chain->id,
            'status' => ParseRun::STATUS_CANCEL_REQUESTED,
            'current_step' => 'cancel requested',
            'heartbeat_at' => now()->subMinutes(10),
        ]);

        $this->getJson('/api/admin/parse-runs')
            ->assertOk()
            ->assertJsonPath('items.0.id', $run->id)
            ->assertJsonPath('items.0.status', ParseRun::STATUS_CANCELLED)
            ->assertJsonPath('items.0.current_step', 'cancelled after stale worker');

        $this->assertDatabaseHas('parse_runs', [
            'id' => $run->id,
            'status' => ParseRun::STATUS_CANCELLED,
            'current_step' => 'cancelled after stale worker',
        ]);
    }

    public function test_admin_can_delete_finished_parse_run(): void
    {
        $chain = Chain::query()->create(['code' => 'metro', 'name' => 'Metro']);
        $run = ParseRun::query()->create([
            'chain_id' => $chain->id,
            'status' => ParseRun::STATUS_SUCCESS,
            'current_step' => 'completed',
            'finished_at' => now(),
        ]);

        $this->deleteJson("/api/admin/parse-runs/{$run->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('parse_runs', [
            'id' => $run->id,
        ]);
    }

    public function test_admin_cannot_delete_active_parse_run(): void
    {
        $chain = Chain::query()->create(['code' => 'lenta', 'name' => 'Lenta']);
        $run = ParseRun::query()->create([
            'chain_id' => $chain->id,
            'status' => ParseRun::STATUS_RUNNING,
            'current_step' => 'running',
        ]);

        $this->deleteJson("/api/admin/parse-runs/{$run->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Активный запуск нельзя удалить. Сначала остановите его или дождитесь завершения.');

        $this->assertDatabaseHas('parse_runs', [
            'id' => $run->id,
            'status' => ParseRun::STATUS_RUNNING,
        ]);
    }
}
