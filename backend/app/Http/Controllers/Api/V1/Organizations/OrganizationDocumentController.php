<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Organizations\OrganizationDocuments;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Proof of business for approval (0057). Owners upload and manage; platform
 * admins read them while reviewing. Other team members have no access.
 */
class OrganizationDocumentController extends Controller
{
    public function __construct(private readonly OrganizationDocuments $documents) {}

    public function index(Organization $organization): JsonResponse
    {
        Gate::authorize('manage', $organization);

        return ApiResponse::success($this->documents->list($organization)->values());
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $request->validate([
            'file' => ['required', 'file'],
            'doc_type' => ['required', Rule::in(OrganizationDocuments::TYPES)],
        ], ['file.required' => 'Choose a file to upload.']);

        $document = $this->documents->upload($organization, $request->user(), $request->file('file'), (string) $request->string('doc_type'));

        return ApiResponse::success($document, 'Document uploaded.', 201);
    }

    public function destroy(Request $request, Organization $organization, string $document): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $this->documents->remove($organization, $request->user(), $document);

        return ApiResponse::success(null, 'Document removed.');
    }

    public function file(Request $request, Organization $organization, string $document): BinaryFileResponse
    {
        if ($request->user()->account_type !== AccountType::Admin) {
            Gate::authorize('manage', $organization);
        }
        $file = $this->documents->file($organization, $document);
        $name = str_replace(['"', '\\'], '', $file['name']);

        return response()->file($file['path'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
