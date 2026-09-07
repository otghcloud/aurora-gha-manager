<?php

namespace App\Enums;

/**
 * Lifecycle state of a provisioned runner VM.
 */
enum RunnerState: int
{
    case Spawning = 0;
    case Idle = 1;
    case Busy = 2;
    case Reaping = 3;
    case Failed = 4;
    case Destroyed = 5;

    /**
     * States that count towards capacity limits.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Spawning, self::Idle, self::Busy];
    }

    /**
     * @return array<int, int>
     */
    public static function activeValues(): array
    {
        return array_map(fn (self $state): int => $state->value, self::active());
    }

    /**
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Whether this state counts toward active capacity. */
    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /** Return the human-readable lifecycle label. */
    public function label(): string
    {
        return match ($this) {
            self::Spawning => 'Spawning',
            self::Idle => 'Idle',
            self::Busy => 'Busy',
            self::Reaping => 'Reaping',
            self::Failed => 'Failed',
            self::Destroyed => 'Destroyed',
        };
    }

    /** Return the presentation colour token associated with the state. */
    public function colour(): string
    {
        return match ($this) {
            self::Spawning => 'azure',
            self::Idle => 'green',
            self::Busy => 'blue',
            self::Reaping => 'orange',
            self::Failed => 'red',
            self::Destroyed => 'secondary',
        };
    }
}
