<?php

namespace App\Http\Controllers\Runners;

use App\Http\Controllers\Controller;
use App\DataTables\Runners\RunnersDataTable;
use App\Enums\RunnerState;
use App\Models\Infrastructure\Environment;
use App\Models\Runners\Runner;
use App\Services\Provisioning\EnvironmentServices;
use App\Support\RunnerTimeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

/**
 * Presents tracked runner history and lifecycle actions.
 */
class RunnerController extends Controller
{
    /** Display tracked runner history. */
    public function index(RunnersDataTable $dataTable): mixed
    {
        return $dataTable->render('pages.runners.index', [
            'environments' => Environment::orderBy('name')->get(),
        ]);
    }

    /** Display one runner and its lifecycle history. */
    public function show(Runner $runner): View
    {
        $runner->load(['environment', 'pool.runnerTemplate', 'proxmoxTarget', 'servedJob']);
        $job = $runner->servedJob;

        return view('pages.runners.show', [
            'runner' => $runner,
            'job' => $job,
            'timeline' => RunnerTimeline::for($runner, $job)->reverse(),
            'lifetimeSeconds' => RunnerTimeline::lifetimeSeconds($runner),
        ]);
    }

    /** Destroy a tracked runner through the environment service graph. */
    public function destroy(Runner $runner, EnvironmentServices $services): RedirectResponse
    {
        if ($runner->state === RunnerState::Destroyed) {
            return back()->with('error', 'That runner has already been destroyed.');
        }

        try {
            $services->provisioner($runner->environment)->destroy($runner, 'destroyed from the web interface');
        } catch (Throwable $e) {
            return back()->with('error', 'Could not destroy the runner: '.$e->getMessage());
        }

        return redirect()
            ->route('runners.index')
            ->with('success', "Destroyed VM {$runner->vmid}.");
    }
}
