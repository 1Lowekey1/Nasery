<?php

namespace App\Console\Commands;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Services\Quotations\QuotationWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auto-close (paper: "bids with no activity for a system-configured period
 * are automatically closed"). Cancels open inquiries with no activity (the
 * latest quotation sent, else the invitations) for `inquiry_auto_close_days`
 * (platform setting, default 30), and cancels their open quotations. Runs daily as the login role (console),
 * writing the status change and an audit row together.
 */
#[Signature('nasery:inquiries:close-stale {--dry-run : Only report what would be closed}')]
#[Description('Cancel open inquiries older than the configured auto-close window')]
class CloseStaleInquiries extends Command
{
    public function handle(QuotationWorkflow $quotations): int
    {
        $days = (int) (json_decode((string) DB::table('platform_settings')->where('key', 'inquiry_auto_close_days')->value('value')) ?? 30);

        if ($days < 1) {
            $this->info('Auto-close is disabled (inquiry_auto_close_days < 1).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        // Last activity = the latest quotation sent, else when invitations were
        // sent (inquiries have no submitted_at).
        $stale = DB::table('inquiries as i')
            ->whereIn('i.status', [InquiryStatus::Submitted->value, InquiryStatus::ReceivingBids->value])
            ->whereNull('i.deleted_at')
            ->whereRaw('coalesce(
                (select max(q.submitted_at) from quotations q where q.inquiry_id = i.id),
                (select min(io.created_at) from inquiry_organizations io where io.inquiry_id = i.id)
            ) < ?', [$cutoff])
            ->pluck('i.id');

        $this->line(sprintf('Open inquiries sent before %s: %d', $cutoff->toDateString(), $stale->count()));

        if ($this->option('dry-run') || $stale->isEmpty()) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($stale, $days, $quotations) {
            DB::table('inquiries')->whereIn('id', $stale)->update(['status' => InquiryStatus::Cancelled->value, 'updated_at' => now()]);

            Inquiry::query()->whereIn('id', $stale)->get()
                ->each(fn (Inquiry $inquiry) => $quotations->cancelActive($inquiry, 'Inquiry closed after no activity'));

            DB::table('audit_logs')->insert($stale->map(fn (string $id) => [
                'action' => 'inquiry.auto_closed',
                'entity_type' => 'inquiries',
                'entity_id' => $id,
                'reason' => "No accepted quotation within {$days} days",
                'new_values' => json_encode(['status' => 'cancelled']),
            ])->all());
        });

        $this->info("Closed {$stale->count()} inquiries.");

        return self::SUCCESS;
    }
}
