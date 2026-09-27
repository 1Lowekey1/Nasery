<?php

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\NormalizesPhone;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrganizationRequest extends FormRequest
{
    use NormalizesPhone;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $profile = (array) $this->input('profile', []);

        if (array_key_exists('contact_phone', $profile)) {
            $profile['contact_phone'] = $this->normalizePhone($profile['contact_phone']);
        }

        $this->merge(['profile' => $profile]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:200'],
            'display_name' => ['required', 'string', 'max:120'],
            'profile' => ['required', 'array'],
            ...ProfileRules::rules('profile.'),
        ];
    }

    public function messages(): array
    {
        return ['profile.contact_phone.regex' => 'Enter a valid Philippine mobile number (e.g. 0917 123 4567).'];
    }
}
