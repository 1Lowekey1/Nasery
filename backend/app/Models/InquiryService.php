<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * public.inquiry_services (0007): a service the client asks for, either from
 * the catalog (service_id) or free text (custom_service_name).
 */
#[Fillable(['inquiry_id', 'service_id', 'custom_service_name', 'notes'])]
class InquiryService extends Model
{
    const UPDATED_AT = null;

    protected $attributes = [
        'service_id' => null,
        'custom_service_name' => null,
        'notes' => null,
    ];
}
