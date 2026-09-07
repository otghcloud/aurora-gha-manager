<?php

namespace App\Enums;

/**
 * Why a runner VM was created, recorded once and never changed. A warm runner that later picks up
 * a job still reads as `Warm`; the job it served is tracked separately.
 */
enum SpawnReason: int
{
    case Job = 0;
    case Warm = 1;

    /** Return the human-readable reason label. */
    public function label(): string
    {
        return match ($this) {
            self::Job => 'On demand',
            self::Warm => 'Warm pool',
        };
    }

    /** Return the presentation colour token associated with the reason. */
    public function colour(): string
    {
        return match ($this) {
            self::Job => 'purple',
            self::Warm => 'teal',
        };
    }
}
