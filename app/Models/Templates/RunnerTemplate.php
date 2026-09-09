<?php

namespace App\Models\Templates;

use App\Enums\PoolOs;
use App\Models\Builds\ImageBuild;
use App\Models\Concerns\HasBreadcrumbLabel;
use App\Models\Credentials\Credential;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Pools\Pool;
use App\Services\Builds\TemplateCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A bootable runner image definition and its per-target build mappings.
 */
class RunnerTemplate extends Model
{
    use HasBreadcrumbLabel;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'os' => PoolOs::class,
        ];
    }

    public function setOsAttribute(PoolOs|string|int $value): void
    {
        if (is_string($value)) {
            $value = PoolOs::fromSlug($value);
        }

        $this->attributes['os'] = $value instanceof PoolOs ? $value->value : $value;
    }

    /** Whether the template has a catalog entry and usable target mappings. */
    public function isBuildable(): bool
    {
        return $this->template_catalog_id !== null
            && $this->targetMappings()
                ->whereNotNull('build_iso_file')
                ->where('build_iso_file', '!=', '')
                ->exists();
    }

    /**
     * Name given to the Proxmox VM template this builds, so it is recognisable next to the runners.
     */
    /** Return the recognizable Proxmox VM template name. */
    public function vmName(): string
    {
        return 'tpl-'.Str::slug($this->name);
    }

    /**
     * Ids of the nodes this template has a usable image on; runners cannot spawn anywhere else.
     *
     * @return array<int, int>
     */
    /** @return array<int, int> Target IDs with completed template builds. */
    public function builtTargetIds(): array
    {
        return $this->targetMappings()
            ->whereNotNull('runner_template_target.template_vmid')
            ->where('runner_template_target.availability_status', 'available')
            ->pluck('proxmox_targets.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Nodes this template can actually be built on right now.
     *
     * @return Collection<int, ProxmoxTarget>
     */
    /** @return Collection<int, ProxmoxTarget> Targets with buildable mappings. */
    public function buildableTargets(): Collection
    {
        $entry = app(TemplateCatalog::class)->entryForId($this->template_catalog_id);

        if ($entry === null || ! $entry->isBuildable()) {
            return new Collection;
        }

        return $this->targetMappings()
            ->whereNotNull('runner_template_target.build_iso_file')
            ->where('runner_template_target.build_iso_file', '!=', '')
            ->whereNotNull('proxmox_targets.build_iso_storage')
            ->whereNotNull('proxmox_targets.build_vm_storage')
            ->get();
    }

    /** @return BelongsTo<Environment, $this> Owning environment. */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<Credential, $this> Build credential. */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /** @return HasMany<Pool, $this> Runner pools using this template. */
    public function pools(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    /** @return HasMany<ImageBuild, $this> Image builds for this template. */
    public function imageBuilds(): HasMany
    {
        return $this->hasMany(ImageBuild::class);
    }

    /** @return BelongsToMany<ProxmoxTarget, $this> Mapped Proxmox targets. */
    public function proxmoxTargets(): BelongsToMany
    {
        return $this->targetMappings()->withPivot(['template_vmid', 'generation', 'version', 'build_iso_file', 'build_iso_url', 'build_cores', 'build_memory_mb', 'build_disk_gb', 'availability_status', 'last_built_at']);
    }

    public function targetMappings(): BelongsToMany
    {
        return $this->belongsToMany(ProxmoxTarget::class, 'runner_template_target', 'runner_template_id', 'proxmox_target_id')
            ->using(RunnerTemplateTarget::class)
            ->withPivot(['template_vmid', 'generation', 'version', 'build_iso_file', 'build_iso_url', 'build_cores', 'build_memory_mb', 'build_disk_gb', 'availability_status', 'last_built_at']);
    }
}
