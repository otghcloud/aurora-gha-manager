<?php

namespace App\Models\Webhooks;

use App\Models\GitHub\GitHubAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An incoming GitHub webhook delivery retained for audit and troubleshooting.
 */
class WebhookDelivery extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GitHubAccount, $this> Account receiving the delivery. */
    public function githubAccount(): BelongsTo
    {
        return $this->belongsTo(GitHubAccount::class);
    }
}
