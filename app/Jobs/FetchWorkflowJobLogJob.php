<?php

namespace App\Jobs;

use App\Models\Builds\LogEntry;
use App\Models\GitHub\WorkflowJob;
use App\Services\GitHub\GitHubClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches and stores the log for a completed GitHub workflow job.
 */
class FetchWorkflowJobLogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @param int $workflowJobId Identifier of the workflow job to fetch. */
    public function __construct(public readonly int $workflowJobId)
    {
        $this->onQueue('provision');

        // GitHub takes a moment to finalise the log after the completed webhook fires.
        $this->delay(now()->addSeconds(20));
    }

    /** Fetch and persist the job log when it is available. */
    public function handle(): void
    {
        $job = WorkflowJob::with('environment.githubAccount')->find($this->workflowJobId);
        $account = $job?->environment?->githubAccount;

        if ($job === null || $account === null || $job->log_fetched_at !== null) {
            return;
        }

        $log = (new GitHubClient($account))->jobLog($job->repository_full_name, $job->github_job_id);

        if ($log === null) {
            $job->forceFill(['log_fetched_at' => now()])->save();

            return;
        }

        $path = $this->path($job);
        file_put_contents($path, $log);

        LogEntry::store($job, LogEntry::CHANNEL_JOB, $log);

        $job->forceFill(['log_path' => $path, 'log_fetched_at' => now()])->save();
    }

    /** @return array<int, int> Retry delays in seconds. */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** Record the final log-fetch failure for diagnostics. */
    public function failed(?Throwable $e): void
    {
        Log::warning('Could not store the log for a workflow job', [
            'workflow_job' => $this->workflowJobId,
            'error' => $e?->getMessage(),
        ]);
    }

    private function path(WorkflowJob $job): string
    {
        $directory = config('jobs.log_directory');

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        return $directory.'/job-'.$job->id.'.log';
    }
}
