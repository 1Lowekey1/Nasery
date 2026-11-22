<?php

namespace App\Services\Inquiries;

use App\Models\Inquiry;
use App\Models\Organization;
use App\Services\Media\ImageStore;
use App\Support\Database\DatabaseContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 0062: a request's mood board (colour palettes, inspiration pictures), up to
 * Inquiry::MOOD_BOARD_LIMIT. The client adds and removes pictures; after the
 * booking (field test, Nov 2026) the booked organizer's team adds its own and
 * removes only those. Nothing changes once the request is cancelled or its
 * event is over. Stored as [{path, caption, by: organization id|null, by_name}].
 */
class MoodBoards
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly ImageStore $images,
    ) {}

    /** Cancelled requests and finished (or cancelled) events keep their pictures as they are. */
    public function closed(Inquiry $inquiry): bool
    {
        return $inquiry->status->value === 'cancelled' || $this->db->system('inquiry.event_done', fn () => DB::table('events')
            ->where('inquiry_id', $inquiry->id)->whereNull('deleted_at')->whereIn('status', ['completed', 'cancelled'])->exists());
    }

    /** The organizer whose event came from this request, while the event isn't over. */
    public function bookedBy(Inquiry $inquiry, Organization $organization): bool
    {
        return $inquiry->status->value !== 'cancelled' && $this->db->system('inquiry.booked_by', fn () => DB::table('events')
            ->where('inquiry_id', $inquiry->id)->where('organization_id', $organization->id)->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'cancelled'])->exists());
    }

    public function add(Inquiry $inquiry, UploadedFile $file, ?string $caption, ?Organization $by = null): void
    {
        if (count($inquiry->mood_board ?? []) >= Inquiry::MOOD_BOARD_LIMIT) {
            throw ValidationException::withMessages(['image' => 'The mood board holds up to '.Inquiry::MOOD_BOARD_LIMIT.' pictures. Remove one first.']);
        }

        $path = $this->images->store($file, 'mood');
        $item = ['path' => $path, 'caption' => filled($caption) ? trim((string) $caption) : null, 'by' => $by?->id, 'by_name' => $by?->display_name];
        try {
            $this->db->system('inquiry.mood_add', function () use ($inquiry, $item) {
                $board = json_decode((string) DB::table('inquiries')->where('id', $inquiry->id)->lockForUpdate()->value('mood_board'), true) ?: [];
                $board[] = $item;
                DB::table('inquiries')->where('id', $inquiry->id)->update(['mood_board' => json_encode(array_slice($board, 0, Inquiry::MOOD_BOARD_LIMIT)), 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            $this->images->delete($path);
            throw $e;
        }
    }

    /**
     * Remove one picture (by its id). With $onlyBy, only a picture that organization added.
     *
     * @return bool whether a picture was removed
     */
    public function remove(Inquiry $inquiry, string $imageId, ?Organization $onlyBy = null): bool
    {
        $path = "mood-boards/{$imageId}.webp";
        $removed = $this->db->system('inquiry.mood_remove', function () use ($inquiry, $path, $onlyBy) {
            $board = json_decode((string) DB::table('inquiries')->where('id', $inquiry->id)->lockForUpdate()->value('mood_board'), true) ?: [];
            $kept = array_values(array_filter($board, fn ($i) => ($i['path'] ?? null) !== $path || ($onlyBy && ($i['by'] ?? null) !== $onlyBy->id)));
            if (count($kept) === count($board)) {
                return false;
            }
            DB::table('inquiries')->where('id', $inquiry->id)->update(['mood_board' => json_encode($kept), 'updated_at' => now()]);

            return true;
        });
        if ($removed) {
            $this->images->delete($path);
        }

        return $removed;
    }
}
