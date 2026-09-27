<?php

namespace App\Http\Requests\Organizations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApprovalDecisionRequest extends FormRequest
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
            'status' => ['required', Rule::in(['under_review', 'approved', 'rejected', 'suspended'])],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:status,rejected,suspended'],
        ];
    }
}
