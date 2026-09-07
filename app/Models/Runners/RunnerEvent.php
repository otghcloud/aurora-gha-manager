<?php

namespace App\Models\Runners;

use App\Enums\RunnerState;
use App\Models\Runners\Runner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable state transition recorded for a runner.
 */
class RunnerEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_state' => RunnerState::class,
            'to_state' => RunnerState::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Runner, $this> Runner owning this event. */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(Runner::class);
    }
}
