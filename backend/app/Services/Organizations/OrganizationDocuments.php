<?php

namespace App\Services\Organizations;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Proof of business an organizer uploads for approval (0057), so nobody has
 * to e-mail permits around. Unlike pictures these are kept as they are, since
 * the admin needs the real document. Files are identified by their CONTENT,
 * saved under a random name in a private folder outside the web root, and
 * only ever handed out by OrganizationDocumentController.
 */
class OrganizationDocuments
{
    public const TYPES = ['business_permit', 'registration', 'bir', 'valid_id', 'other'];

    /** Files one organization can keep on record. */
    private const MAX_FILES = 10;

    /** @var array<string, string> mime => extension */
    private const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
    ) {}

    /** Whether approving needs at least one uploaded file. */
    public static function required(): bool
    {
        return (bool) config('nasery.organizer_documents.required', true);
    }

    public function count(Organization $organization): int
    {
        return $this->db->system('org.documents_count', fn () => DB::table('organization_documents')
            ->where('organization_id', $organization->id)->count());
    }

    /** @return Collection<int, array<string, mixed>> what the browser may see (never the stored path) */
    public function list(Organization $organization): Collection
    {
        return $this->db->system('org.documents_list', fn () => DB::table('organization_documents')
            ->where('organization_id', $organization->id)->orderByDesc('created_at')->get())
            ->map(fn ($d) => [
                'id' => $d->id,
                'doc_type' => $d->doc_type,
                'original_name' => $d->original_name,
                'mime_type' => $d->mime_type,
                'size_bytes' => (int) $d->size_bytes,
                'uploaded_at' => $d->created_at,
            ]);
    }

    /** @return array<string, mixed> */
    public function upload(Organization $organization, User $user, UploadedFile $file, string $type): array
    {
        if (! $file->isValid()) {
            $this->fail('The upload did not finish. Please try again.');
        }
        $max = (int) config('nasery.organizer_documents.max_bytes');
        if ($file->getSize() > $max) {
            $this->fail('Choose a file under '.round($max / 1048576).' MB.');
        }

        try {
            $bytes = (string) $file->get();
        } catch (\Throwable) {
            $this->fail('That file could not be read. Choose a PDF, JPG or PNG.');
        }

        return $this->save($organization, $user, $bytes, $type, $file->getClientOriginalName());
    }

    /**
     * Keep the given content as a document (also used for demo data).
     *
     * @return array<string, mixed>
     */
    public function save(Organization $organization, User $user, string $bytes, string $type, string $name): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! isset(self::MIMES[$mime])) {
            $this->fail('Only PDF, JPG or PNG files are accepted.');
        }
        if ($this->count($organization) >= self::MAX_FILES) {
            $this->fail('You can keep up to '.self::MAX_FILES.' documents. Remove one first.');
        }

        // Only the visible name is kept from the upload: no folders or control characters.
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', ' ', basename(str_replace('\\', '/', $name))));
        $name = Str::limit($name !== '' ? $name : 'document.'.self::MIMES[$mime], 190, '');

        $relative = $organization->id.'/'.Str::uuid().'.'.self::MIMES[$mime];
        $path = $this->absolute($relative);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('Could not store the document.');
        }

        try {
            $id = (string) Str::uuid();
            $this->db->system('org.document_add', fn () => DB::table('organization_documents')->insert([
                'id' => $id,
                'organization_id' => $organization->id,
                'uploaded_by' => $user->id,
                'doc_type' => $type,
                'original_name' => $name,
                'stored_path' => $relative,
                'mime_type' => $mime,
                'size_bytes' => strlen($bytes),
            ]));
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        $this->audit->record('organization.document_uploaded', $organization,
            new: ['document_id' => $id, 'doc_type' => $type], organizationId: $organization->id, actorUserId: $user->id);

        return $this->list($organization)->firstWhere('id', $id);
    }

    /** An approved organization's documents are its record, so they stay. */
    public function remove(Organization $organization, User $user, string $documentId): void
    {
        if ($organization->approval_status->value === 'approved') {
            throw new ApiException(ErrorCode::Conflict, 'Documents of an approved organization can’t be removed.', 409);
        }

        $document = $this->find($organization, $documentId);
        $this->db->system('org.document_remove', fn () => DB::table('organization_documents')->where('id', $document->id)->delete());
        @unlink($this->absolute($document->stored_path));

        $this->audit->record('organization.document_removed', $organization,
            old: ['document_id' => $document->id, 'doc_type' => $document->doc_type], organizationId: $organization->id, actorUserId: $user->id);
    }

    /** @return array{path: string, mime: string, name: string} */
    public function file(Organization $organization, string $documentId): array
    {
        $document = $this->find($organization, $documentId);
        $path = $this->absolute($document->stored_path);
        if (! is_file($path)) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        return ['path' => $path, 'mime' => $document->mime_type, 'name' => $document->original_name];
    }

    public function absolute(string $relative): string
    {
        return rtrim((string) config('nasery.organizer_documents.root'), '/\\').'/'.$relative;
    }

    private function find(Organization $organization, string $documentId): object
    {
        return $this->db->system('org.document_read', fn () => DB::table('organization_documents')
            ->where('organization_id', $organization->id)->where('id', $documentId)->first())
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
