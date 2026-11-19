<?php

namespace App\Http\Requests\Runners;

use Illuminate\Foundation\Http\FormRequest;

class RunnerTemplateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'runner_template_id' => ['required', 'integer', 'exists:runner_templates,id'],
            'proxmox_target_id' => ['required', 'integer', 'exists:proxmox_targets,id'],
            'source_storage' => ['required', 'string', 'max:255'],
            'template_vmid' => ['required', 'integer', 'min:100', 'max:999999999'],
            'version' => ['nullable', 'string', 'max:255'],
        ];
    }
}
