<?php

namespace App\Enums;

/**
 * Operating systems supported by runner pools.
 */
enum PoolOs: int
{
    case Linux = 0;
    case Windows = 1;
    case MacOS = 2;

    /** Return the default runner installation directory for this OS. */
    public function defaultRunnerDir(): string
    {
        return match ($this) {
            self::Linux => '/opt/actions-runner',
            self::Windows => 'C:\\actions-runner',
            self::MacOS => '/usr/local/actions-runner',
        };
    }

    /** Return the default remote management port for this OS. */
    public function remotePort(): int
    {
        return match ($this) {
            self::Linux => 22,
            self::Windows => 5985,
            self::MacOS => 22,
        };
    }

    /** Return the human-readable operating-system label. */
    public function label(): string
    {
        return match ($this) {
            self::Linux => 'Linux',
            self::Windows => 'Windows',
            self::MacOS => 'MacOS',
        };
    }

    /** Return the external catalog and label slug. */
    public function slug(): string
    {
        return match ($this) {
            self::Linux => 'linux',
            self::Windows => 'windows',
            self::MacOS => 'macos',
        };
    }

    /** Convert an external operating-system slug to its persisted enum. */
    public static function fromSlug(string $slug): self
    {
        return match ($slug) {
            'linux' => self::Linux,
            'windows' => self::Windows,
            'macos' => self::MacOS,
            default => throw new ValueError("Unsupported pool operating system: {$slug}"),
        };
    }
}
