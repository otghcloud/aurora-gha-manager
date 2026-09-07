<?php

namespace App\Models\Infrastructure;

use App\Models\Concerns\HasBreadcrumbLabel;
use App\Models\Pools\Pool;
use App\Models\Runners\Runner;
use App\Models\Templates\RunnerTemplate;
use App\Models\Templates\RunnerTemplateTarget;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Proxmox node or endpoint that can host runner and template VMs.
 */
class ProxmoxTarget extends Model
{
    use HasBreadcrumbLabel;
    use HasFactory;

    protected $guarded = ['id'];

    /** Standard login ('password', ticket auth) or API token ('api_token', the default). */
    public const AUTH_REALM_API_TOKEN = 'api_token';

    public const AUTH_REALM_PASSWORD = 'password';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'proxmox_verify_tls' => 'boolean',
            'proxmox_token_secret' => 'encrypted',
            'proxmox_password' => 'encrypted',
            'current_vm_count' => 'integer',
            'max_total_vms' => 'integer',
            'last_health_check_at' => 'datetime',
            'drained_at' => 'datetime',
            'template_vmid_range_start' => 'integer',
            'template_vmid_range_end' => 'integer',
            'runner_vmid_range_start' => 'integer',
            'runner_vmid_range_end' => 'integer',
            'vlan_tag' => 'integer',
        ];
    }

    /** Whether the target uses password rather than API-token authentication. */
    public function usesPasswordAuth(): bool
    {
        return $this->proxmox_auth_realm === self::AUTH_REALM_PASSWORD;
    }

    /**
     * The `netN` value Proxmox expects for a VM on this node's network.
     */
    /** Resolve the configured network adapter model. */
    public function networkAdapter(string $model = 'virtio'): string
    {
        $adapter = $model.',bridge='.($this->network_bridge ?: 'vmbr0');

        return $this->vlan_tag === null ? $adapter : $adapter.',tag='.$this->vlan_tag;
    }

    /** @return BelongsToMany<RunnerTemplate, $this> Templates available on this target. */
    public function runnerTemplates(): BelongsToMany
    {
        return $this->belongsToMany(RunnerTemplate::class, 'runner_template_target', 'proxmox_target_id', 'runner_template_id')
            ->using(RunnerTemplateTarget::class)
            ->withPivot(['template_vmid', 'generation', 'build_iso_file', 'build_iso_url', 'build_cores', 'build_memory_mb', 'build_disk_gb', 'availability_status', 'last_built_at']);
    }

    /** @return BelongsToMany<Pool, $this> Pools assigned to this target. */
    public function pools(): BelongsToMany
    {
        return $this->belongsToMany(Pool::class, 'pool_proxmox_target')
            ->withPivot(['preference', 'min_idle_runners', 'max_concurrent'])
            ->withTimestamps();
    }

    /** @return HasMany<Runner, $this> Runners hosted by this target. */
    public function runners(): HasMany
    {
        return $this->hasMany(Runner::class, 'proxmox_target_id');
    }
}
