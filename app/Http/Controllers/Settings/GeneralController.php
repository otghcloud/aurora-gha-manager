<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Builds\ImageBuild;
use App\Models\GitHub\WorkflowJob;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Pools\Pool;
use App\Models\Runners\Runner;
use App\Models\Templates\RunnerTemplate;
use App\Services\Builds\TemplateCatalog;
use App\Services\SettingsRepository;
use Composer\InstalledVersions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manages general application settings and status information.
 */
class GeneralController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** Display general application status and catalog information. */
    public function overview(TemplateCatalog $catalog): View
    {
        return view('pages.settings.overview', [
            'settings' => $this->settings->all(),
            'templatesVersion' => $catalog->imageBuilderVersion(),
            'nodeCount' => ProxmoxTarget::count(),
            'poolCount' => Pool::count(),
            'templateCount' => RunnerTemplate::count(),
            'runnerCount' => Runner::count(),
            'jobCount' => WorkflowJob::count(),
            'buildCount' => ImageBuild::count(),
            'plugins' => $this->installedPlugins(),
            'templateSources' => [
                'Template repository' => $this->safeRepositorySource((string) config('builds.templates_repository', '')),
                'Bundled catalog directory' => (string) config('builds.image_builder_path', ''),
                'Downloaded catalogs directory' => (string) config('builds.templates_install_path', ''),
                'Active catalog directory' => $catalog->root(),
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function installedPlugins(): array
    {
        return collect(InstalledVersions::getInstalledPackages())
            ->filter(fn (string $package): bool => str_starts_with($package, 'otghcloud/aurora-gha-plugin-'))
            ->mapWithKeys(fn (string $package): array => [$package => InstalledVersions::getPrettyVersion($package) ?? 'Unknown'])
            ->all();
    }

    /** Remove user info and query parameters before showing a configured repository URL. */
    private function safeRepositorySource(string $source): string
    {
        $parts = parse_url($source);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $source;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');
    }

    /** Display general application settings. */
    public function application(): View
    {
        return view('pages.settings.application', [
            'settings' => $this->settings->all(),
        ]);
    }

    /** Persist general application settings. */
    public function updateApplication(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_url' => ['required', 'url'],
            'build_api_url' => ['nullable', 'url'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $this->settings->setMany([
            'app_url' => rtrim($validated['app_url'], '/'),
            'build_api_url' => rtrim((string) ($validated['build_api_url'] ?? ''), '/'),
            'timezone' => $validated['timezone'],
        ]);

        return redirect()
            ->route('settings.application')
            ->with('success', 'Application settings saved.');
    }
}
