<?php

namespace App\Http\Controllers;

use App\DataTables\Runners\ActiveRunnersDataTable;
use App\DataTables\Runners\RecentRunnersDataTable;
use App\Enums\RunnerState;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use Illuminate\View\View;

/**
 * Presents the operational dashboard and runner activity tables.
 */
class DashboardController extends Controller
{
    /** Display dashboard summary cards and runner activity tables. */
    public function index(ActiveRunnersDataTable $activeDataTable, RecentRunnersDataTable $recentDataTable): View
    {
        $environments = Environment::query()
            ->withCount([
                'runners as active_runners_count' => fn ($query) => $query->whereIn('state', RunnerState::activeValues()),
                'pools',
                'runnerTemplates',
            ])
            ->addSelect([
                'proxmox_targets_count' => ProxmoxTarget::selectRaw('COUNT(DISTINCT pool_proxmox_target.proxmox_target_id)')
                    ->join('pool_proxmox_target', 'proxmox_targets.id', '=', 'pool_proxmox_target.proxmox_target_id')
                    ->join('pools', 'pool_proxmox_target.pool_id', '=', 'pools.id')
                    ->whereColumn('pools.environment_id', '=', 'environments.id'),
            ])
            ->orderBy('name')
            ->get();

        return view('pages.dashboard', [
            'environments' => $environments,
            'targetCapacity' => ProxmoxTarget::sum('max_total_vms'),
            'activeRunnersTable' => $activeDataTable->html(),
            'recentRunnersTable' => $recentDataTable->html(),
        ]);
    }

    /** Return the active runner DataTable response. */
    public function activeRunners(ActiveRunnersDataTable $dataTable): mixed
    {
        return $dataTable->render('pages.dashboard');
    }

    /** Return the recent runner DataTable response. */
    public function recentRunners(RecentRunnersDataTable $dataTable): mixed
    {
        return $dataTable->render('pages.dashboard');
    }
}
