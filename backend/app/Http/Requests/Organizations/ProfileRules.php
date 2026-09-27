<?php

namespace App\Http\Requests\Organizations;

/**
 * Shared validation for organization_profiles fields.
 */
final class ProfileRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = '', bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            "{$prefix}business_name" => [$req, 'string', 'max:150'],
            "{$prefix}description" => ['nullable', 'string', 'max:2000'],
            "{$prefix}location_text" => [$req, 'string', 'max:255'],
            "{$prefix}latitude" => ['nullable', 'numeric', 'between:-90,90', "required_with:{$prefix}longitude"],
            "{$prefix}longitude" => ['nullable', 'numeric', 'between:-180,180', "required_with:{$prefix}latitude"],
            "{$prefix}contact_email" => [$req, 'string', 'email:rfc', 'max:254'],
            "{$prefix}contact_phone" => ['nullable', 'string', 'regex:/^\+639\d{9}$/'],
            "{$prefix}cover_image_url" => ['nullable', 'url:https', 'max:2048'],
        ];
    }
}
