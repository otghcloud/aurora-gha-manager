<?php

namespace App\Models\Templates;

use App\Models\Infrastructure\ProxmoxTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A template VM superseded by a rebuild, kept until nothing is cloned from it any more.
 */
class RetiredTemplateVmid extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vmid' => 'integer',
            'generation' => 'integer',
            'retired_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RunnerTemplate, $this> Superseded template. */
    public function runnerTemplate(): BelongsTo
    {
        return $this->belongsTo(RunnerTemplate::class);
    }

    /** @return BelongsTo<ProxmoxTarget, $this> Hosting target. */
    public function proxmoxTarget(): BelongsTo
    {
        return $this->belongsTo(ProxmoxTarget::class);
    }
}
