<?php

namespace App\Console\Commands;

use App\Services\Media\ImageStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delete uploaded pictures nothing points to anymore (deleted accounts,
 * purged demo/test data, an upload interrupted between saving the file and
 * the database). Files younger than an hour are left alone, so an upload in
 * progress is never touched. Runs after nasery:test-data:purge and daily.
 */
#[Signature('nasery:media:prune {--dry-run : Only list what would be deleted}')]
#[Description('Delete uploaded pictures that no account, organizer or event uses')]
class PruneMedia extends Command
{
    public function handle(ImageStore $images): int
    {
        $root = rtrim((string) config('nasery.media.root'), '\\/');
        if (! is_dir($root)) {
            $this->info('No uploaded pictures yet.');

            return self::SUCCESS;
        }

        $used = collect()
            ->merge(DB::connection('system')->table('public.users')->whereNotNull('avatar_path')->pluck('avatar_path'))
            ->merge(DB::connection('system')->table('public.organization_profiles')->whereNotNull('logo_path')->pluck('logo_path'))
            ->merge(DB::connection('system')->table('public.organization_profiles')->whereNotNull('cover_path')->pluck('cover_path'))
            ->merge(DB::connection('system')->table('public.events')->whereNotNull('cover_path')->pluck('cover_path'))
            // 0066: event floor plans (skipped if the column was rolled back).
            ->merge(Schema::connection('system')->hasColumn('events', 'floor_plan_path')
                ? DB::connection('system')->table('public.events')->whereNotNull('floor_plan_path')->pluck('floor_plan_path') : [])
            // 0062/0063: price list item pictures (galleries) and clients' mood boards.
            ->merge(DB::connection('system')->table('public.organization_price_options')->whereNotNull('image_path')->pluck('image_path'))
            ->merge(DB::connection('system')->table('public.organization_price_options')->where('images', '!=', '[]')->pluck('images')
                ->flatMap(fn ($list) => array_filter(json_decode((string) $list, true) ?: [], 'is_string')))
            ->merge(DB::connection('system')->table('public.inquiries')->where('mood_board', '!=', '[]')->pluck('mood_board')
                ->flatMap(fn ($board) => array_column(json_decode((string) $board, true) ?: [], 'path')))
            ->flip();

        $removed = 0;
        foreach (ImageStore::KINDS as [$folder]) {
            foreach (glob($root.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                $relative = $folder.'/'.basename($file);
                if ($used->has($relative) || filemtime($file) > time() - 3600) {
                    continue;
                }
                $this->line(($this->option('dry-run') ? 'Would delete ' : 'Deleted ').$relative);
                if (! $this->option('dry-run')) {
                    @unlink($file);
                }
                $removed++;
            }
        }

        $this->info($removed === 0 ? 'Nothing to delete.' : "{$removed} unused picture(s) ".($this->option('dry-run') ? 'found.' : 'deleted.'));

        return self::SUCCESS;
    }
}
