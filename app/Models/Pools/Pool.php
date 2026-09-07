<?php

namespace App\Models\Pools;

use App\Enums\RunnerState;
use App\Models\Concerns\HasBreadcrumbLabel;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Runners\Runner;
use App\Models\Templates\RunnerTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A capacity and label policy for runners cloned from one template.
 */
class Pool extends Model
{
    use HasBreadcrumbLabel;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'labels' => 'array',
            'cores' => 'integer',
            'memory' => 'integer',
            'boot_timeout_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Environment, $this> Owning environment. */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<RunnerTemplate, $this> Source runner template. */
    public function runnerTemplate(): BelongsTo
    {
        return $this->belongsTo(RunnerTemplate::class);
    }

    /** @return HasMany<Runner, $this> Runners created from this pool. */
    public function runners(): HasMany
    {
        return $this->hasMany(Runner::class);
    }

    /**
     * The nodes this pool may run on, with the warm pool and concurrency limits for each.
     */
    /** @return BelongsToMany<ProxmoxTarget, $this> Eligible Proxmox targets. */
    public function proxmoxTargets(): BelongsToMany
    {
        return $this->belongsToMany(ProxmoxTarget::class, 'pool_proxmox_target')
            ->withPivot(['preference', 'min_idle_runners', 'max_concurrent'])
            ->withTimestamps();
    }

    /** Restrict the query to enabled pools. */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Baked into the template image, so it is always the OS default.
     */
    /** Return the runner installation directory for this pool's OS. */
    public function runnerDirectory(): string
    {
        return $this->runnerTemplate->os->defaultRunnerDir();
    }

    /** Count active runners across all targets. */
    public function activeRunnerCount(): int
    {
        return $this->runners()->whereIn('state', RunnerState::activeValues())->count();
    }

    /**
     * The pool-wide warm pool target: the sum of every node's minimum.
     */
    /** Return the aggregate configured warm-runner floor. */
    public function totalMinIdleRunners(): int
    {
        return (int) $this->proxmoxTargets->sum(fn (ProxmoxTarget $target): int => $this->minIdleRunnersOn($target));
    }

    /**
     * The pool-wide ceiling: the sum of every node's maximum.
     */
    /** Return the aggregate configured concurrency limit. */
    public function totalMaxConcurrent(): int
    {
        return (int) $this->proxmoxTargets->sum(fn (ProxmoxTarget $target): int => $this->maxConcurrentOn($target));
    }

    /** Whether this pool is enabled on the target. */
    public function runsOn(ProxmoxTarget $target): bool
    {
        return $this->pivotFor($target) !== null;
    }

    public function minIdleRunnersOn(ProxmoxTarget $target): int
    {
        return (int) ($this->pivotFor($target)?->min_idle_runners ?? 0);
    }

    public function maxConcurrentOn(ProxmoxTarget $target): int
    {
        return (int) ($this->pivotFor($target)?->max_concurrent ?? 0);
    }

    public function preferenceFor(ProxmoxTarget $target): int
    {
        return (int) ($this->pivotFor($target)?->preference ?? 0);
    }

    public function activeRunnerCountOn(ProxmoxTarget $target): int
    {
        return $this->runners()
            ->where('proxmox_target_id', $target->getKey())
            ->whereIn('state', RunnerState::activeValues())
            ->count();
    }

    public function idleAndSpawningRunnerCountOn(ProxmoxTarget $target): int
    {
        return $this->runners()
            ->where('proxmox_target_id', $target->getKey())
            ->whereIn('state', [RunnerState::Spawning->value, RunnerState::Idle->value])
            ->count();
    }

    /** Return available runner capacity on the target. */
    public function availableCapacityOn(ProxmoxTarget $target): int
    {
        return max(0, $this->maxConcurrentOn($target) - $this->activeRunnerCountOn($target));
    }

    /** Whether another runner can be spawned on the target. */
    public function hasCapacityOn(ProxmoxTarget $target): bool
    {
        return $this->runsOn($target) && $this->availableCapacityOn($target) > 0;
    }

    /**
     * Warm runners this node is short of, capped by its own concurrency limit.
     */
    /** Return the number of warm runners needed on the target. */
    public function warmRunnersToSpawnOn(ProxmoxTarget $target): int
    {
        $needed = $this->minIdleRunnersOn($target) - $this->idleAndSpawningRunnerCountOn($target);

        return max(0, min($needed, $this->availableCapacityOn($target)));
    }

    private function pivotFor(ProxmoxTarget $target): ?object
    {
        return $this->proxmoxTargets->firstWhere('id', $target->getKey())?->pivot;
    }
}
