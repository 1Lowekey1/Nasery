<?php

namespace App\Http\Requests\Events;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Event fields. On create, sessions[] may be given too; times are local
 * ("2027-02-14T17:00") in the event timezone.
 */
class EventRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'event_type_id' => ['sometimes', 'nullable', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'timezone' => ['sometimes', 'timezone:all'],
            'overall_capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'venue_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'venue_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'visibility' => ['sometimes', Rule::in(Event::VISIBILITIES)],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sessions' => $creating ? ['sometimes', 'array', 'max:30'] : ['prohibited'],
            'sessions.*.name' => ['nullable', 'string', 'max:120'],
            'sessions.*.starts_at' => ['required', 'date'],
            'sessions.*.ends_at' => ['required', 'date'],
        ];
    }
}
