<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use NormalizesPhone;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
            'phone' => $this->normalizePhone($this->phone),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'regex:/^\+639\d{9}$/'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            // Admin accounts are never self-registered.
            'account_type' => ['required', Rule::in(['client', 'business'])],
            // The e-mailed code that confirms reusing an old account (202 conversion_required).
            'code' => ['nullable', 'string', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid Philippine mobile number (e.g. 0917 123 4567).'];
    }
}
