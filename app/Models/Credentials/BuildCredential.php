<?php

namespace App\Models\Credentials;

use App\Enums\PoolOs;
use App\Models\Builds\ImageBuild;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of the credential material used by one image build.
 */
class BuildCredential extends Model
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

    /** @return BelongsTo<ImageBuild, $this> Source image build. */
    public function imageBuild(): BelongsTo
    {
        return $this->belongsTo(ImageBuild::class);
    }

    /** @return BelongsTo<Credential, $this> Original credential. */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
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

    /** Resolve the snapshot username or supplied fallback. */
    public function resolvedUsername(?string $fallback = null): string
    {
        return (string) ($this->username ?: $fallback ?: 'runner');
    }
}
