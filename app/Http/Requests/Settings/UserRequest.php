<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates application user profile and password fields.
 */
class UserRequest extends FormRequest
{
    /** Allow authenticated administrators to submit user changes. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> Validation rules for user fields. */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => [$user === null ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
        ];
    }
}
