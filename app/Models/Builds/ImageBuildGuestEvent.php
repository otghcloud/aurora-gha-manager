<?php

namespace App\Models\Builds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable callback received from a guest-owned Cloud Image build. */
class ImageBuildGuestEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ImageBuild, $this> */
    public function imageBuild(): BelongsTo
    {
        return $this->belongsTo(ImageBuild::class);
    }
}
