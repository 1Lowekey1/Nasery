<?php

namespace App\Http\Requests\Quotations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quotation content: package name, proposal (pitch / service plan), terms and
 * the cost breakdown. The total is computed from the lines by the server.
 */
class QuotationRequest extends FormRequest
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
        return [
            'package_name' => ['nullable', 'string', 'max:150'],
            'proposal' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'min:2', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:100000', 'decimal:0,2'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'items.*.organization_service_id' => ['nullable', 'uuid'],
            'submit' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one line to the cost breakdown.',
            'items.min' => 'Add at least one line to the cost breakdown.',
        ];
    }
}
