<?php

namespace App\Models\GitHub;

use App\Enums\JobConclusion;
use App\Models\Builds\LogEntry;
use App\Models\Infrastructure\Environment;
use App\Models\Runners\Runner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A GitHub Actions job this installation served, built from the workflow_job webhook payloads.
 */
class WorkflowJob extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'github_job_id' => 'integer',
            'github_run_id' => 'integer',
            'run_attempt' => 'integer',
            'labels' => 'array',
            'steps' => 'array',
            'conclusion' => JobConclusion::class,
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'log_fetched_at' => 'datetime',
        ];
    }

    /** Normalize GitHub conclusion slugs before integer enum persistence. */
    public function setConclusionAttribute(JobConclusion|string|int|null $value): void
    {
        if (is_string($value)) {
            $value = JobConclusion::fromSlug($value);
        }

        $this->attributes['conclusion'] = $value instanceof JobConclusion ? $value->value : $value;
    }

    /** @return BelongsTo<Environment, $this> Owning environment. */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<Runner, $this> Runner that served the job. */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(Runner::class);
    }

    /** Restrict the query to one environment. */
    public function scopeForEnvironment(Builder $query, Environment $environment): Builder
    {
        return $query->where('environment_id', $environment->getKey());
    }

    /** Return the repository name without its owner prefix. */
    public function repositoryName(): string
    {
        return str_contains($this->repository_full_name, '/')
            ? explode('/', $this->repository_full_name, 2)[1]
            : $this->repository_full_name;
    }

    /**
     * How long the job occupied a runner, or null while it is still going.
     */
    /** Return runner occupancy duration, or null while incomplete. */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->completed_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }

    /**
     * How long the job sat in GitHub's queue before a runner picked it up.
     */
    /** Return GitHub queue wait duration, or null while incomplete. */
    public function queueWaitSeconds(): ?int
    {
        if ($this->queued_at === null || $this->started_at === null) {
            return null;
        }

        return max(0, (int) $this->queued_at->diffInSeconds($this->started_at));
    }

    /** Whether a readable raw log file is present. */
    public function hasLog(): bool
    {
        return $this->log_path !== null && is_readable($this->log_path);
    }

    public function getBreadcrumbLabel(): string
    {
        return (string) ($this->job_name ?: $this->github_job_id);
    }

    /** @return MorphMany<LogEntry, $this> Stored job logs. */
    public function logEntries(): MorphMany
    {
        return $this->morphMany(LogEntry::class, 'loggable');
    }

    /** Return the retained workflow job log, if one exists. */
    public function storedLog(): ?LogEntry
    {
        return $this->logEntries()->where('channel', LogEntry::CHANNEL_JOB)->first();
    }
}
