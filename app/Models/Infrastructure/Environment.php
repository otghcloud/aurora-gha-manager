<?php

namespace App\Models\Infrastructure;

use App\Models\Concerns\HasBreadcrumbLabel;
use App\Models\Builds\ImageBuild;
use App\Models\GitHub\GitHubAccount;
use App\Models\Pools\Pool;
use App\Models\Runners\Runner;
use App\Models\Templates\RunnerTemplate;
use App\Models\Webhooks\WebhookDelivery;
use App\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An isolated runner environment with its GitHub, Proxmox, and lifecycle settings.
 */
class Environment extends Model
{
    use HasBreadcrumbLabel;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'keep_failed_vms' => 'boolean',
            'max_lifetime_seconds' => 'integer',
            'idle_timeout_seconds' => 'integer',
            'job_claim_timeout_seconds' => 'integer',
        ];
    }

    /** @return HasMany<RunnerTemplate, $this> Environment templates. */
    public function runnerTemplates(): HasMany
    {
        return $this->hasMany(RunnerTemplate::class);
    }

    /** @return BelongsTo<GitHubAccount, $this> Connected GitHub account. */
    public function githubAccount(): BelongsTo
    {
        return $this->belongsTo(GitHubAccount::class, 'github_account_id');
    }

    /** @return HasMany<Pool, $this> Runner pools. */
    public function pools(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    /** @return HasMany<Runner, $this> Tracked runners. */
    public function runners(): HasMany
    {
        return $this->hasMany(Runner::class);
    }

    /** @return HasMany<ImageBuild, $this> Image builds. */
    public function imageBuilds(): HasMany
    {
        return $this->hasMany(ImageBuild::class);
    }

    /** @return HasMany<WebhookDelivery, $this> Webhook audit records. */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    protected function webhookUrl(): Attribute
    {
        return Attribute::get(function (): string {
            $appUrl = app(SettingsRepository::class)->get('app_url', config('app.url'));

            return rtrim((string) $appUrl, '/').'/webhook/'.$this->githubAccount->webhook_id;
        });
    }

    /** Use the environment slug for route model binding. */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Resolve the pool whose labels satisfy every label a job asked for.
     *
     * GitHub sends the job's `labels` array; a pool matches when that array is a
     * subset of the pool's labels. The most specific pool wins, mirroring the
     * The pool with the smallest matching label set wins.
     *
     * @param  array<int, string>  $labels
     */
    /** @param array<int, string> $labels Resolve a pool matching all requested labels. */
    public function poolForLabels(array $labels): ?Pool
    {
        if ($labels === []) {
            return null;
        }

        $wanted = array_map('strtolower', $labels);

        if (! in_array('self-hosted', $wanted, true)
            || count(array_filter($wanted, fn (string $label): bool => $label !== 'self-hosted')) < 1) {
            return null;
        }

        return $this->pools()
            ->where('enabled', true)
            ->get()
            ->filter(function (Pool $pool) use ($wanted): bool {
                $available = array_map('strtolower', $pool->labels);

                return array_diff($wanted, $available) === [];
            })
            ->sortBy(fn (Pool $pool): int => count($pool->labels))
            ->first();
    }
}
