<?php

namespace App\Services;

use App\Exceptions\ParseRunCancelled;
use App\Models\ParseRun;

class ParseRunProgress
{
    private ?ParseRun $run = null;

    public function useRun(ParseRun $run): void
    {
        $this->run = $run;
    }

    public function update(string $step, array $counts = []): void
    {
        if (! $this->run) {
            return;
        }

        $this->ensureNotCancelled();

        $payload = [
            'current_step' => $step,
            'heartbeat_at' => now(),
        ];

        foreach (['stores_count', 'products_count', 'offers_count'] as $field) {
            if (array_key_exists($field, $counts)) {
                $payload[$field] = $counts[$field];
            }
        }

        $this->run->update($payload);
    }

    public function ensureNotCancelled(): void
    {
        if (! $this->run) {
            return;
        }

        $this->run->refresh();

        if ($this->run->status === ParseRun::STATUS_CANCEL_REQUESTED) {
            throw new ParseRunCancelled();
        }
    }
}
