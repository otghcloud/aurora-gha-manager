<?php

namespace App\Enums;

/**
 * Lifecycle state of an image build.
 */
enum BuildStatus: int
{
    case Queued = 0;
    case Running = 1;
    case Succeeded = 2;
    case Failed = 3;
    case Cancelled = 4;

    /** Whether the build has reached a terminal state. */
    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    /** Return the human-readable status label used by the UI. */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Return the presentation colour token associated with the status. */
    public function colour(): string
    {
        return match ($this) {
            self::Queued => 'secondary',
            self::Running => 'blue',
            self::Succeeded => 'green',
            self::Failed => 'red',
            self::Cancelled => 'orange',
        };
    }
}
