<?php

namespace App\Services\Builds;

use App\Contracts\Builds\BuilderInterface;
use App\Exceptions\ProvisioningException;

/**
 * Registry of image builders contributed by the core application and plugins.
 */
final class BuilderRegistry
{
    /** @var array<string, BuilderInterface> */
    private array $builders = [];

    /** Register a builder, rejecting duplicate type identifiers. */
    public function register(BuilderInterface $builder): void
    {
        $type = $builder->type();

        if (isset($this->builders[$type])) {
            throw new ProvisioningException('A builder is already registered for '.$type.'.');
        }

        $this->builders[$type] = $builder;
    }

    /** @throws ProvisioningException When no builder is registered for the type. */
    public function forType(string $type): BuilderInterface
    {
        $builder = $this->builders[$type] ?? null;

        if ($builder === null) {
            throw new ProvisioningException('No builder is registered for '.$type.'.');
        }

        return $builder;
    }

    /** @return list<string> */
    /** @return list<string> Registered builder type identifiers. */
    public function types(): array
    {
        return array_keys($this->builders);
    }
}
