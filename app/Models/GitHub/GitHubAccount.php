<?php

namespace App\Models\GitHub;

use App\Models\Infrastructure\Environment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GitHub organization or account configured to deliver workflow events.
 */
class GitHubAccount extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $table = 'github_accounts';

    protected $hidden = [
        'github_token',
        'github_webhook_secret',
    ];

    /** @return array<int, string> UUID-backed identifier columns. */
    public function uniqueIds(): array
    {
        return ['webhook_id'];
    }

    /** Return the account label used in breadcrumbs. */
    public function getBreadcrumbLabel(): string
    {
        return (string) ($this->login ?: $this->getKey());
    }

    protected function casts(): array
    {
        return [
            'github_token' => 'encrypted',
            'github_webhook_secret' => 'encrypted',
            'github_runner_group_id' => 'integer',
        ];
    }

    /** @return HasMany<Environment, $this> Environments using this account. */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class, 'github_account_id');
    }
}
