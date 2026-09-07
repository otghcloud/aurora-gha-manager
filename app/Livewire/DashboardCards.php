<?php

namespace App\Livewire;

use App\Enums\BuildStatus;
use App\Enums\RunnerState;
use App\Models\Builds\ImageBuild;
use App\Models\Runners\Runner;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Livewire dashboard widget for runner, job, and build summary cards.
 */
class DashboardCards extends Component
{
    public function render(): View
    {
        $stateCounts = Runner::query()
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state')
            ->mapWithKeys(fn (int $total, int $state): array => [RunnerState::from($state)->name => $total]);

        $activeBuildsCount = ImageBuild::query()
            ->whereIn('status', [BuildStatus::Queued->value, BuildStatus::Running->value])
            ->count();

        return view('livewire.dashboard-cards', [
            'stateCounts' => $stateCounts,
            'activeBuildsCount' => $activeBuildsCount,
        ]);
    }
}
