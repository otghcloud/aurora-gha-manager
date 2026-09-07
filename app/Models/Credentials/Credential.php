<?php

namespace App\Models\Credentials;

use App\Models\Builds\ImageBuild;
use App\Models\Templates\RunnerTemplate;
use App\Enums\PoolOs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stored credentials used to access runner VMs for a supported operating system.
 */
class Credential extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'private_key',
        'public_key',
    ];

    protected function casts(): array
    {
        return [
            'os' => PoolOs::class,
            'password' => 'encrypted',
            'private_key' => 'encrypted',
            'public_key' => 'encrypted',
        ];
    }

    /** Normalize an OS slug or enum before integer persistence. */
    public function setOsAttribute(PoolOs|string|int $value): void
    {
        if (is_string($value)) {
            $value = PoolOs::fromSlug($value);
        }

        $this->attributes['os'] = $value instanceof PoolOs ? $value->value : $value;
    }

    /** @return HasMany<ImageBuild, $this> Builds using this credential. */
    public function imageBuilds(): HasMany
    {
        return $this->hasMany(ImageBuild::class);
    }

    /** @return HasMany<RunnerTemplate, $this> Templates using this credential. */
    public function runnerTemplates(): HasMany
    {
        return $this->hasMany(RunnerTemplate::class);
    }

    /** Whether a password or complete SSH key pair is available. */
    public function hasAuthenticationMaterial(): bool
    {
        return filled($this->password) || (filled($this->private_key) && filled($this->public_key));
    }

    /** Cloud-init images disable password SSH by default, so builders need this specifically. */
    /** Whether both SSH private and public keys are available. */
    public function hasSshKeyMaterial(): bool
    {
        return filled($this->private_key) && filled($this->public_key);
    }

    /** Resolve the configured username or supplied fallback. */
    public function resolvedUsername(?string $fallback = null): string
    {
        return (string) ($this->username ?: $fallback ?: 'runner');
    }
}
