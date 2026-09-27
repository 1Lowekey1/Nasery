<?php

namespace App\Http\Controllers\Api\V1\Quotations;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Quotation;
use App\Services\Quotations\ClientQuotations;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quotations on the client's own inquiries. {inquiry} and {quotation} bind
 * under RLS: only the client's inquiries and their non-draft quotations.
 */
class ClientQuotationController extends Controller
{
    public function __construct(private readonly ClientQuotations $quotations) {}

    public function index(Inquiry $inquiry): JsonResponse
    {
        return ApiResponse::success($this->quotations->forInquiry($inquiry));
    }

    public function accept(Quotation $quotation): JsonResponse
    {
        $this->quotations->accept($quotation);

        return ApiResponse::success(
            ['quotation_id' => $quotation->id, 'inquiry_id' => $quotation->inquiry_id],
            'Quotation accepted. The organizer can now see your contact details.',
        );
    }

    public function decline(Request $request, Quotation $quotation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->quotations->decline($quotation, $data['reason']);

        return ApiResponse::success(['quotation_id' => $quotation->id], 'Quotation declined.');
    }
}
