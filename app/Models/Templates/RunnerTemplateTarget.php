<?php

namespace App\Models\Templates;

use App\Models\Infrastructure\ProxmoxTarget;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot record containing the physical build mapping for a runner template target.
 */
class RunnerTemplateTarget extends Pivot
{
    protected $table = 'runner_template_target';

    public $incrementing = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'template_vmid' => 'integer',
            'generation' => 'integer',
            'build_cores' => 'integer',
            'build_memory_mb' => 'integer',
            'build_disk_gb' => 'integer',
            'last_built_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RunnerTemplate, $this> Mapped runner template. */
    public function runnerTemplate(): BelongsTo
    {
        return $this->belongsTo(RunnerTemplate::class);
    }

    /** @return BelongsTo<ProxmoxTarget, $this> Mapped target. */
    public function proxmoxTarget(): BelongsTo
    {
        return $this->belongsTo(ProxmoxTarget::class);
    }
}
