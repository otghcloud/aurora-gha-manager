<?php

namespace App\Enums;

/**
 * Conclusion reported for a completed GitHub Actions job.
 */
enum JobConclusion: int
{
    case Success = 0;
    case Failure = 1;
    case Cancelled = 2;
    case Skipped = 3;
    case TimedOut = 4;
    case ActionRequired = 5;
    case Neutral = 6;

    /** Return the human-readable conclusion label. */
    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failure => 'Failure',
            self::Cancelled => 'Cancelled',
            self::Skipped => 'Skipped',
            self::TimedOut => 'Timed out',
            self::ActionRequired => 'Action required',
            self::Neutral => 'Neutral',
        };
    }

    /** Convert a GitHub API conclusion slug to its persisted enum. */
    public static function fromSlug(string $slug): self
    {
        return match ($slug) {
            'success' => self::Success,
            'failure' => self::Failure,
            'cancelled' => self::Cancelled,
            'skipped' => self::Skipped,
            'timed_out' => self::TimedOut,
            'action_required' => self::ActionRequired,
            'neutral' => self::Neutral,
            default => throw new ValueError("Unsupported job conclusion: {$slug}"),
        };
    }

    /** Return the presentation colour token associated with the conclusion. */
    public function colour(): string
    {
        return match ($this) {
            self::Success => 'green',
            self::Failure, self::TimedOut => 'red',
            self::Cancelled, self::Skipped, self::Neutral => 'secondary',
            self::ActionRequired => 'orange',
        };
    }

    /** Return the icon token associated with the conclusion. */
    public function icon(): string
    {
        return match ($this) {
            self::Success => 'fa-solid fa-circle-check',
            self::Failure, self::TimedOut => 'fa-solid fa-circle-xmark',
            self::Cancelled, self::Skipped => 'fa-solid fa-ban',
            self::ActionRequired => 'fa-solid fa-triangle-exclamation',
            self::Neutral => 'fa-solid fa-circle-minus',
        };
    }
}
