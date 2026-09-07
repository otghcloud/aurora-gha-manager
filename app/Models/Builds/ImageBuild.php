<?php

namespace App\Models\Builds;

use App\Enums\BuildStatus;
use App\Models\Credentials\BuildCredential;
use App\Models\Credentials\Credential;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Templates\RunnerTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An image build scheduled for a runner template and Proxmox target.
 */
class ImageBuild extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BuildStatus::class,
            'exit_code' => 'integer',
            'process_pid' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'proxmox_target_id' => 'integer',
            'template_vmid' => 'integer',
            'sequence' => 'integer',
            'template_catalog_id' => 'string',
            'builder_type' => 'string',
            'credential_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Environment, $this> Owning environment. */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<RunnerTemplate, $this> Template being built. */
    public function runnerTemplate(): BelongsTo
    {
        return $this->belongsTo(RunnerTemplate::class);
    }

    /** @return BelongsTo<User, $this> User who started the build. */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /** @return BelongsTo<ProxmoxTarget, $this> Target receiving the build VM. */
    public function proxmoxTarget(): BelongsTo
    {
        return $this->belongsTo(ProxmoxTarget::class);
    }

    /** @return BelongsTo<Credential, $this> Source credential. */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /** @return HasOne<BuildCredential, $this> Immutable credential snapshot. */
    public function credentialSnapshot(): HasOne
    {
        return $this->hasOne(BuildCredential::class);
    }

    /** @return MorphMany<LogEntry, $this> Stored build logs. */
    public function logEntries(): MorphMany
    {
        return $this->morphMany(LogEntry::class, 'loggable');
    }

    /** Return the label used for build breadcrumbs. */
    public function getBreadcrumbLabel(): string
    {
        return (string) ($this->template_catalog_id ?: 'Build '.$this->getKey());
    }

    /** Return the retained build log, if one exists. */
    public function storedLog(): ?LogEntry
    {
        return $this->logEntries()->where('channel', LogEntry::CHANNEL_BUILD)->first();
    }
}
