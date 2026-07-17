<?php

namespace App\Services;

class QueueWorkerKickstarter
{
    public function canRunDirectly(): bool
    {
        return app()->environment('local')
            && ! app()->environment('testing')
            && config('queue.default') === 'database';
    }

    public function runParseRun(int $parseRunId): void
    {
        if (! $this->canRunDirectly()) {
            return;
        }

        $php = PHP_BINARY;
        $artisan = base_path('artisan');

        if (PHP_OS_FAMILY === 'Windows') {
            $command = sprintf(
                'cmd /C start "" /B %s %s discounts:run-parse-run %d > NUL 2>&1',
                escapeshellarg($php),
                escapeshellarg($artisan),
                $parseRunId,
            );
        } else {
            $command = sprintf(
                '%s %s discounts:run-parse-run %d > /dev/null 2>&1 &',
                escapeshellarg($php),
                escapeshellarg($artisan),
                $parseRunId,
            );
        }

        @pclose(@popen($command, 'r'));
    }

    public function kick(): void
    {
        if (! app()->environment('local') || config('queue.default') !== 'database') {
            return;
        }

        $php = PHP_BINARY;
        $artisan = base_path('artisan');

        if (PHP_OS_FAMILY === 'Windows') {
            $command = sprintf(
                'cmd /C start "" /B %s %s queue:work database --queue=default --stop-when-empty --tries=1 --timeout=900 > NUL 2>&1',
                escapeshellarg($php),
                escapeshellarg($artisan),
            );
        } else {
            $command = sprintf(
                '%s %s queue:work database --queue=default --stop-when-empty --tries=1 --timeout=900 > /dev/null 2>&1 &',
                escapeshellarg($php),
                escapeshellarg($artisan),
            );
        }

        @pclose(@popen($command, 'r'));
    }
}
