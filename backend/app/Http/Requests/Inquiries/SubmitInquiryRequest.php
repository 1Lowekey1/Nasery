<?php

namespace App\Http\Requests\Inquiries;

use App\Services\Inquiries\InquiryMatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitInquiryRequest extends FormRequest
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
            // direct: the client picks organizers; open: NaSeRy invites every
            // listed organizer matching the services within the radius.
            'mode' => ['required', Rule::in(['direct', 'open'])],
            'organization_ids' => ['required_if:mode,direct', 'prohibited_if:mode,open', 'array', 'min:1', 'max:'.InquiryMatcher::MAX_DIRECT_INVITES],
            'organization_ids.*' => ['uuid', 'distinct'],
            'match_radius_km' => ['required_if:mode,open', 'prohibited_if:mode,direct', 'nullable', 'numeric', 'min:1', 'max:500'],
        ];
    }
}
