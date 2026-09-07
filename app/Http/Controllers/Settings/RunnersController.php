<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manages runner lifecycle and capacity settings.
 */
class RunnersController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** Display runner lifecycle settings. */
    public function index(): View
    {
        return view('pages.settings.runners', [
            'settings' => $this->settings->all(),
        ]);
    }

    /** Persist runner lifecycle settings. */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'runner_name_prefix' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9-]+$/i'],
        ]);

        $this->settings->set(SettingsRepository::RUNNER_NAME_PREFIX, $validated['runner_name_prefix']);

        return redirect()
            ->route('settings.runners.index')
            ->with('success', 'Runner settings saved.');
    }
}
