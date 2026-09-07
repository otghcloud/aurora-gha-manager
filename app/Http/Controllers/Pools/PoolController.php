<?php

namespace App\Http\Controllers\Pools;

use App\Http\Controllers\Controller;
use App\DataTables\Pools\PoolsDataTable;
use App\Http\Requests\Pools\PoolRequest;
use App\Models\Infrastructure\Environment;
use App\Models\Pools\Pool;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Templates\RunnerTemplate;
use App\Support\LabelPresets;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Manages runner pool capacity, labels, and target preferences.
 */
class PoolController extends Controller
{
    /** Display configured runner pools. */
    public function index(PoolsDataTable $dataTable): mixed
    {
        return $dataTable->render('pages.pools.index');
    }

    /** Display the pool creation form. */
    public function create(): View
    {
        return view('pages.pools.create', $this->formData(new Pool([
            'enabled' => true,
            'cores' => 4,
            'memory' => 8192,
            'boot_timeout_seconds' => 300,
            'labels' => [],
        ])));
    }

    /** Persist a new runner pool. */
    public function store(PoolRequest $request): RedirectResponse
    {
        $pool = Pool::create($request->safe()->except('nodes'));
        $pool->proxmoxTargets()->sync($request->nodeLimits());

        return redirect()
            ->route('pools.show', $pool)
            ->with('success', "Pool {$pool->name} created.");
    }

    /** Display a pool and its runner activity. */
    public function show(Pool $pool): View
    {
        $pool->load(['environment', 'runnerTemplate', 'proxmoxTargets']);

        return view('pages.pools.show', [
            'pool' => $pool,
            'buildableTargetIds' => $pool->runnerTemplate?->builtTargetIds() ?? [],
            'activeRunners' => $pool->runners()->with('proxmoxTarget')->active()->get(),
        ]);
    }

    /** Display the pool edit form. */
    public function edit(Pool $pool): View
    {
        return view('pages.pools.edit', $this->formData($pool));
    }

    /** Update an existing runner pool. */
    public function update(PoolRequest $request, Pool $pool): RedirectResponse
    {
        $pool->update($request->safe()->except('nodes'));
        $pool->proxmoxTargets()->sync($request->nodeLimits());

        return redirect()
            ->route('pools.show', $pool)
            ->with('success', 'Pool updated.');
    }

    /** Delete a runner pool. */
    public function destroy(Pool $pool): RedirectResponse
    {
        $pool->delete();

        return redirect()
            ->route('pools.index')
            ->with('success', 'Pool deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Pool $pool): array
    {
        $templates = RunnerTemplate::with('environment')->orderBy('name')->get();

        return [
            'pool' => $pool,
            'environments' => Environment::orderBy('name')->get(),
            'templates' => $templates,
            'targets' => ProxmoxTarget::orderBy('name')->get(),
            'nodeLimits' => $pool->exists
                ? $pool->proxmoxTargets->keyBy('id')->map(fn (ProxmoxTarget $target): array => [
                    'preference' => $target->pivot->preference,
                    'min_idle_runners' => $target->pivot->min_idle_runners,
                    'max_concurrent' => $target->pivot->max_concurrent,
                ])->all()
                : [],
            'builtTargets' => $templates->mapWithKeys(fn (RunnerTemplate $template): array => [
                $template->id => $template->builtTargetIds(),
            ])->all(),
            'presets' => LabelPresets::all(),
        ];
    }
}
