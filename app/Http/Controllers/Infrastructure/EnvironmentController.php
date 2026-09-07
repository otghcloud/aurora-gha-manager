<?php

namespace App\Http\Controllers\Infrastructure;

use App\Http\Controllers\Controller;
use App\DataTables\EnvironmentsDataTable;
use App\Http\Requests\Infrastructure\EnvironmentRequest;
use App\Models\Infrastructure\Environment;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\ProxmoxTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Manages runner environment configuration.
 */
class EnvironmentController extends Controller
{
    /** Display configured environments. */
    public function index(EnvironmentsDataTable $dataTable): mixed
    {
        return $dataTable->render('pages.environments.index');
    }

    /** Display the environment creation form. */
    public function create(): View
    {
        return view('pages.environments.create', [
            'environment' => new Environment,
            'accounts' => GitHubAccount::orderBy('login')->get(),
        ]);
    }

    /** Persist a new environment. */
    public function store(EnvironmentRequest $request): RedirectResponse
    {
        $environment = Environment::create($request->validated());

        return redirect()
            ->route('environments.show', $environment)
            ->with('success', 'Environment created.');
    }

    /** Display an environment and its operational resources. */
    public function show(Environment $environment): View
    {
        $environment->load(['pools.runnerTemplate', 'pools.proxmoxTargets', 'runnerTemplates.targetMappings', 'runnerTemplates.imageBuilds', 'githubAccount']);

        return view('pages.environments.show', [
            'environment' => $environment,
            'targets' => ProxmoxTarget::withCount('runnerTemplates')->orderBy('name')->get(),
        ]);
    }

    /** Display the environment edit form. */
    public function edit(Environment $environment): View
    {
        return view('pages.environments.edit', [
            'environment' => $environment,
            'accounts' => GitHubAccount::orderBy('login')->get(),
        ]);
    }

    /** Update an existing environment. */
    public function update(EnvironmentRequest $request, Environment $environment): RedirectResponse
    {
        $data = $request->validated();

        $environment->update($data);

        return redirect()
            ->route('environments.show', $environment)
            ->with('success', 'Environment updated.');
    }

    /** Delete an environment and its dependent configuration. */
    public function destroy(Environment $environment): RedirectResponse
    {
        $environment->delete();

        return redirect()
            ->route('environments.index')
            ->with('success', 'Environment deleted.');
    }
}
