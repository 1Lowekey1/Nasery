<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Social-only accounts (no password yet) may set one without current_password.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => [$this->user()?->password_hash === null ? 'nullable' : 'required', 'string', 'max:128'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }
}
