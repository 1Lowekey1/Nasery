<?php

namespace App\Http\Requests\Inquiries;

use App\Models\Inquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'requested_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.self::lastDate()],
            'guest_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'budget_min' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'budget_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
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

    /** Latest event date accepted: December 31, three years from now (Philippine time). */
    public static function lastDate(): string
    {
        return now('Asia/Manila')->addYears(3)->endOfYear()->toDateString();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'requested_date.after_or_equal' => 'The event date can’t be in the past.',
            'requested_date.before_or_equal' => 'Choose an event date on or before December 31, '.substr(self::lastDate(), 0, 4).'.',
            'requested_date.date_format' => 'Choose a valid event date.',
        ];
    }

    /**
     * Max ≥ min only when both are given ("Under ₱50,000" has no minimum; a
     * plain `gte` rule rejects a null minimum). The stored draft fills in a
     * side the request leaves out.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $draft = $this->route('inquiry') instanceof Inquiry ? $this->route('inquiry') : null;
            $min = $this->has('budget_min') ? $this->input('budget_min') : $draft?->budget_min;
            $max = $this->has('budget_max') ? $this->input('budget_max') : $draft?->budget_max;
            if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min && ! $validator->errors()->has('budget_max')) {
                $validator->errors()->add('budget_max', 'The maximum budget must be at least the minimum budget.');
            }
        }];
    }
}
