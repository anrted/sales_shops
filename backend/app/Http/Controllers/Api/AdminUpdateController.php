<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateSystemJob;
use App\Models\AppUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class AdminUpdateController extends Controller
{
    private function getGitRoot(): string
    {
        if (file_exists('/var/www/app_root/.git')) {
            return '/var/www/app_root';
        }

        return base_path('..');
    }

    public function check(): JsonResponse
    {
        $gitRoot = $this->getGitRoot();

        // 1. Current commit
        $currentProc = new Process(['git', 'rev-parse', 'HEAD'], $gitRoot);
        $currentProc->run();
        $currentCommit = trim($currentProc->getOutput() ?: 'unknown');

        $shortCommitProc = new Process(['git', 'rev-parse', '--short', 'HEAD'], $gitRoot);
        $shortCommitProc->run();
        $shortCommit = trim($shortCommitProc->getOutput() ?: 'unknown');

        // 2. Fetch remote
        $fetchProc = new Process(['git', 'fetch', 'origin'], $gitRoot);
        $fetchProc->run();

        // 3. Remote commit
        $remoteProc = new Process(['git', 'rev-parse', 'origin/main'], $gitRoot);
        $remoteProc->run();
        $remoteCommit = trim($remoteProc->getOutput() ?: $currentCommit);

        // 4. Changelog between HEAD and origin/main
        $logProc = new Process(['git', 'log', 'HEAD..origin/main', '--pretty=format:%h|%an|%s|%ci'], $gitRoot);
        $logProc->run();
        $rawLog = trim($logProc->getOutput());

        $changelog = [];
        if ($rawLog) {
            foreach (explode("\n", $rawLog) as $line) {
                $parts = explode('|', $line, 4);
                if (count($parts) === 4) {
                    $changelog[] = [
                        'hash' => $parts[0],
                        'author' => $parts[1],
                        'message' => $parts[2],
                        'date' => $parts[3],
                    ];
                }
            }
        }

        $activeUpdate = AppUpdate::whereIn('status', ['pending', 'running'])->latest()->first();

        return response()->json([
            'current_commit' => $currentCommit,
            'short_commit' => $shortCommit,
            'remote_commit' => $remoteCommit,
            'has_update' => $currentCommit !== $remoteCommit && ! empty($changelog),
            'commits_behind' => count($changelog),
            'changelog' => $changelog,
            'active_update' => $activeUpdate,
        ]);
    }

    public function start(): JsonResponse
    {
        $running = AppUpdate::whereIn('status', ['pending', 'running'])->first();
        if ($running) {
            return response()->json(['message' => 'An update is already in progress.', 'update' => $running], 400);
        }

        $gitRoot = $this->getGitRoot();
        $currentProc = new Process(['git', 'rev-parse', 'HEAD'], $gitRoot);
        $currentProc->run();
        $previousCommit = trim($currentProc->getOutput() ?: '');

        $remoteProc = new Process(['git', 'rev-parse', 'origin/main'], $gitRoot);
        $remoteProc->run();
        $targetCommit = trim($remoteProc->getOutput() ?: 'origin/main');

        // Fetch changelog
        $logProc = new Process(['git', 'log', 'HEAD..origin/main', '--pretty=format:%h|%s'], $gitRoot);
        $logProc->run();
        $rawLog = trim($logProc->getOutput());

        $changelog = [];
        if ($rawLog) {
            foreach (explode("\n", $rawLog) as $line) {
                $parts = explode('|', $line, 2);
                if (count($parts) === 2) {
                    $changelog[] = ['hash' => $parts[0], 'message' => $parts[1]];
                }
            }
        }

        $appUpdate = AppUpdate::create([
            'target_commit' => $targetCommit,
            'previous_commit' => $previousCommit,
            'status' => 'pending',
            'changelog' => $changelog,
        ]);

        UpdateSystemJob::dispatch($appUpdate, 'origin/main');

        return response()->json([
            'message' => 'Update started successfully.',
            'update' => $appUpdate,
        ]);
    }

    public function status(): JsonResponse
    {
        $activeUpdate = AppUpdate::whereIn('status', ['pending', 'running'])->latest()->first();
        $history = AppUpdate::latest()->limit(15)->get();

        return response()->json([
            'active_update' => $activeUpdate,
            'history' => $history,
        ]);
    }

    public function rollback(Request $request): JsonResponse
    {
        $request->validate([
            'target_commit' => 'required|string',
        ]);

        $running = AppUpdate::whereIn('status', ['pending', 'running'])->first();
        if ($running) {
            return response()->json(['message' => 'An update process is currently active.', 'update' => $running], 400);
        }

        $gitRoot = $this->getGitRoot();
        $currentProc = new Process(['git', 'rev-parse', 'HEAD'], $gitRoot);
        $currentProc->run();
        $previousCommit = trim($currentProc->getOutput() ?: '');

        $appUpdate = AppUpdate::create([
            'target_commit' => $request->target_commit,
            'previous_commit' => $previousCommit,
            'status' => 'pending',
            'changelog' => [['hash' => $request->target_commit, 'message' => 'Rollback to commit']],
        ]);

        UpdateSystemJob::dispatch($appUpdate, $request->target_commit, isRollback: true);

        return response()->json([
            'message' => 'Rollback process started.',
            'update' => $appUpdate,
        ]);
    }
}
