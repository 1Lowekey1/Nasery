<?php

namespace App\Http\Requests\Inquiries;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Draft fields. Completeness (date, guests, location, event type) is only
 * required when the draft is submitted, so a draft can be saved half-done.
 */
class InquiryRequest extends FormRequest
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
            'event_type_id' => ['sometimes', 'nullable', 'uuid'],
            'requested_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before:+3 years'],
            'guest_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'budget_min' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'budget_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2', 'gte:budget_min'],
            'location_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'additional_requirements' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'services' => ['sometimes', 'array', 'max:20'],
            'services.*.service_id' => ['nullable', 'uuid', 'required_without:services.*.custom_service_name'],
            'services.*.custom_service_name' => ['nullable', 'string', 'min:2', 'max:120', 'required_without:services.*.service_id'],
            'services.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['budget_max.gte' => 'The maximum budget must be at least the minimum budget.'];
    }
}
