<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Templates\TemplateSourceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Validates and imports third-party template catalog sources.
 */
class TemplateSourcesController extends Controller
{
    /** Import a source as an inactive template bundle for explicit admin activation. */
    public function import(Request $request, TemplateSourceService $sources): RedirectResponse
    {
        $validated = $request->validate([
            'source_type' => ['required', 'in:url,repository,upload'],
            'url' => ['required_if:source_type,url', 'nullable', 'url', 'max:2048'],
            'repository' => ['required_if:source_type,repository', 'nullable', 'url', 'max:2048'],
            'ref' => ['required_if:source_type,repository', 'nullable', 'string', 'max:128'],
            'archive' => ['required_if:source_type,upload', 'nullable', 'file', 'max:25600'],
        ]);

        try {
            $result = match ($validated['source_type']) {
                'url' => $sources->importUrl($validated['url']),
                'repository' => $sources->importRepository($validated['repository'], $validated['ref']),
                'upload' => $sources->importUpload($request->file('archive')),
            };
        } catch (RuntimeException $exception) {
            return back()
                ->withInput()
                ->with('error', 'Template source was not imported: '.$exception->getMessage());
        }

        return redirect()
            ->route('settings.templates.index')
            ->with('success', "Validated and imported template bundle v{$result['version']} ({$result['templates']} template(s)). It remains inactive until you activate it below.");
    }
}
