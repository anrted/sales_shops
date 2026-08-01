<?php

namespace App\Jobs;

use App\Models\AppUpdate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class UpdateSystemJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600; // 10 minutes timeout

    public function __construct(
        public AppUpdate $appUpdate,
        public string $targetCommit = 'origin/main',
        public bool $isRollback = false
    ) {}

    public function handle(): void
    {
        $this->appUpdate->update([
            'status' => 'running',
            'started_at' => now(),
            'log_output' => "Starting update job...\n",
        ]);

        $scriptPath = base_path('../scripts/update.sh');
        if (! file_exists($scriptPath)) {
            $scriptPath = base_path('../../scripts/update.sh');
        }

        // If inside docker container, check /var/www/app_root/scripts/update.sh
        if (file_exists('/var/www/app_root/scripts/update.sh')) {
            $scriptPath = '/var/www/app_root/scripts/update.sh';
        }

        if (! file_exists($scriptPath)) {
            $errorMsg = "Deployment script not found at: {$scriptPath}";
            $this->appUpdate->update([
                'status' => 'failed',
                'error_message' => $errorMsg,
                'finished_at' => now(),
            ]);

            return;
        }

        $process = new Process(['bash', $scriptPath, $this->targetCommit]);
        $process->setTimeout(600);

        $logBuffer = "";

        try {
            $process->run(function ($type, $buffer) use (&$logBuffer) {
                $logBuffer .= $buffer;
                $this->appUpdate->update(['log_output' => $logBuffer]);
            });

            if ($process->isSuccessful()) {
                $this->appUpdate->update([
                    'status' => $this->isRollback ? 'rolled_back' : 'success',
                    'finished_at' => now(),
                ]);
            } else {
                $this->appUpdate->update([
                    'status' => 'failed',
                    'error_message' => $process->getErrorOutput() ?: 'Process returned error exit code',
                    'finished_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('UpdateSystemJob failed', ['error' => $e->getMessage()]);
            $this->appUpdate->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }
}
