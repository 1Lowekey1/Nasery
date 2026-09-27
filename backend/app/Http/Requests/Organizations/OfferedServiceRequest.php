<?php

namespace App\Http\Requests\Organizations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An offered service is either a catalog service (service_id) or a custom
 * one (custom_name). Both may be given (catalog service with a custom label).
 */
class OfferedServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'service_id' => ['nullable', 'uuid', $creating ? 'required_without:custom_name' : 'sometimes'],
            'custom_name' => ['nullable', 'string', 'max:120', $creating ? 'required_without:service_id' : 'sometimes'],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
