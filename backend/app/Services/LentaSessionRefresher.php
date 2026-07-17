<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class LentaSessionRefresher
{
    public function __construct(
        private readonly LentaSessionSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function refresh(bool $headed = false, int $timeoutSeconds = 90): array
    {
        $scriptPath = base_path('../tools/lenta-session-refresh/refresh.mjs');
        if (!is_file($scriptPath)) {
            throw new RuntimeException("Lenta refresh script was not found at {$scriptPath}.");
        }

        $command = [
            'node',
            $scriptPath,
            '--url='.(string) config('services.lenta.web_url', 'https://lenta.com/'),
            '--timeout='.$timeoutSeconds,
            '--settle-ms=12000',
        ];

        if ($headed) {
            $command[] = '--headed';
        }

        $process = new Process(
            $command,
            dirname($scriptPath),
            $this->nodeEnvironment(),
            null,
            $timeoutSeconds + 30,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException(
                'Lenta browser refresh failed. Make sure Node.js, npm install and Playwright Chromium are installed. '
                .trim($errorOutput)
            );
        }

        $decoded = json_decode($process->getOutput(), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Lenta refresh script returned invalid JSON.');
        }

        return $this->settings->saveCaptured($decoded);
    }

    /**
     * @return array<string, string>
     */
    private function nodeEnvironment(): array
    {
        $tempDirectory = storage_path('app/process-temp');
        if (!File::isDirectory($tempDirectory)) {
            File::makeDirectory($tempDirectory, 0777, true);
        }

        $env = array_merge($_ENV, $_SERVER);

        foreach ([
            'PATH',
            'SystemRoot',
            'COMSPEC',
            'PATHEXT',
            'WINDIR',
            'USERPROFILE',
            'HOMEDRIVE',
            'HOMEPATH',
            'APPDATA',
            'LOCALAPPDATA',
            'NUMBER_OF_PROCESSORS',
            'OS',
            'USERNAME',
        ] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }

        $env['TEMP'] = $tempDirectory;
        $env['TMP'] = $tempDirectory;
        $env['TMPDIR'] = $tempDirectory;

        foreach ([
            'OPENSSL_CONF',
            'OPENSSL_CONFIG',
            'OPENSSL_ENGINES',
            'NODE_OPTIONS',
        ] as $key) {
            unset($env[$key]);
        }

        return array_filter(
            $env,
            static fn (mixed $value): bool => is_scalar($value) && $value !== '',
        );
    }
}
