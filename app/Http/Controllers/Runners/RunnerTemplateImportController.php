<?php

namespace App\Http\Controllers\Runners;

use App\Exceptions\ProxmoxException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Runners\RunnerTemplateImportRequest;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Templates\RunnerTemplate;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Templates\RestoredTemplateImporter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RunnerTemplateImportController extends Controller
{
    public function create(Request $request): View
    {
        $data = $request->validate([
            'proxmox_target_id' => ['nullable', 'integer', 'exists:proxmox_targets,id'],
            'runner_template_id' => ['nullable', 'integer', 'exists:runner_templates,id'],
        ]);
        $targetId = old('proxmox_target_id', $data['proxmox_target_id'] ?? null);
        $target = $targetId === null ? null : ProxmoxTarget::find($targetId);
        $storages = [];
        $storageError = null;

        if ($target !== null) {
            try {
                $storages = collect((new ProxmoxClient($target))->storages('images'))
                    ->filter(fn (array $row): bool => (bool) ($row['enabled'] ?? true) && (bool) ($row['active'] ?? true))
                    ->all();
            } catch (ProxmoxException|ConnectionException $exception) {
                $storageError = 'Could not load template storage: '.$exception->getMessage();
                Log::warning('Could not load storage for restored template import', ['target' => $target->id, 'error' => $exception->getMessage()]);
            }
        }

        return view('pages.templates.import', [
            'targets' => ProxmoxTarget::orderBy('name')->get(),
            'templates' => RunnerTemplate::with('environment')->orderBy('name')->get(),
            'selectedTemplateId' => old('runner_template_id', $data['runner_template_id'] ?? null),
            'target' => $target,
            'storages' => $storages,
            'storageError' => $storageError,
        ]);
    }

    public function store(RunnerTemplateImportRequest $request, RestoredTemplateImporter $importer): RedirectResponse
    {
        $data = $request->validated();
        $template = RunnerTemplate::findOrFail($data['runner_template_id']);
        $target = ProxmoxTarget::findOrFail($data['proxmox_target_id']);

        try {
            $importer->register($template, $target, $data['source_storage'], (int) $data['template_vmid'], $data['version'] ?? null, $request->user()->id);
        } catch (ProxmoxException|ConnectionException $exception) {
            Log::warning('Could not validate restored Proxmox template', ['target' => $target->id, 'vmid' => $data['template_vmid'], 'error' => $exception->getMessage()]);

            return back()->withInput()->with('error', 'Template import failed: '.$exception->getMessage());
        }

        return redirect()->route('templates.show', $template)
            ->with('success', "Imported template VMID {$data['template_vmid']} on {$target->name}. No build was required.");
    }
}
