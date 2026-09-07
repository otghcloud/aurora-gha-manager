<?php

namespace App\Http\Requests\GitHub;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates GitHub account and webhook configuration.
 */
class GitHubAccountRequest extends FormRequest
{
    /** Allow authenticated administrators to submit GitHub account changes. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> Validation rules for GitHub account fields. */
    public function rules(): array
    {
        $account = $this->route('github_account');
        $secret = $account === null ? 'required' : 'nullable';

        return [
            'account_type' => ['required', Rule::in(['organization', 'user'])],
            'login' => ['required', 'string', 'max:255'],
            'webhook_id' => [
                $account === null ? 'nullable' : 'required',
                'uuid',
                Rule::unique('github_accounts', 'webhook_id')->ignore($account),
            ],
            'github_token' => [$secret, 'string'],
            'github_webhook_secret' => [$secret, 'string'],
            'github_api_url' => ['required', 'url', 'max:255'],
            'github_runner_group_id' => ['required', 'integer', 'min:1'],
            'github_work_folder' => ['required', 'string', 'max:255'],
        ];
    }
}
