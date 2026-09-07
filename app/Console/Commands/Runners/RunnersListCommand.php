<?php

namespace App\Console\Commands\Runners;

use App\Enums\RunnerState;
use App\Models\Runners\Runner;
use Illuminate\Console\Command;

/**
 * Lists tracked runner VMs and their current lifecycle state.
 */
class RunnersListCommand extends Command
{
    protected $signature = 'runners:list {--environment= : Limit to one environment slug} {--all : Include destroyed runners}';

    protected $description = 'Show tracked runner VMs';

    /** Render tracked runners and their current states. */
    public function handle(): int
    {
        $runners = Runner::with(['environment', 'pool'])
            ->when($this->option('environment'), fn ($query, $slug) => $query->whereRelation('environment', 'slug', $slug))
            ->unless($this->option('all'), fn ($query) => $query->whereNot('state', RunnerState::Destroyed->value))
            ->orderBy('vmid')
            ->get();

        if ($runners->isEmpty()) {
            $this->components->warn('No runner VMs are being tracked.');

            return self::SUCCESS;
        }

        $this->table(
            ['VMID', 'Environment', 'Pool', 'State', 'Age (s)', 'Runner'],
            $runners->map(fn (Runner $runner): array => [
                $runner->vmid,
                $runner->environment->slug,
                $runner->pool?->name ?? '-',
                $runner->state->value,
                $runner->ageSeconds(),
                $runner->runner_name,
            ])->all()
        );

        return self::SUCCESS;
    }
}
