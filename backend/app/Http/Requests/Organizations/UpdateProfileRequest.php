<?php

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\NormalizesPhone;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    use NormalizesPhone;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('contact_phone')) {
            $this->merge(['contact_phone' => $this->normalizePhone($this->contact_phone)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...ProfileRules::rules(partial: true),
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['contact_phone.regex' => 'Enter a valid Philippine mobile number (e.g. 0917 123 4567).'];
    }
}
