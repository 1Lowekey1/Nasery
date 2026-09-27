<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The attendance report as a PDF (paper: one-click PDF export). The same
 * template renders the organizer's live report and a client's shared
 * version; sections missing from the data (revenue, guest names) are left
 * out. Bars are plain HTML blocks, which dompdf renders reliably.
 */
class ReportPdf
{
    /**
     * @param  array<string, mixed>  $report  from AttendanceReport::build() or a shared snapshot
     * @param  array{version?: int, note?: ?string, shared_at?: string}  $share
     */
    public function render(array $report, array $share = []): string
    {
        return Pdf::loadView('pdf.report', ['r' => $report, 'share' => $share])
            ->setPaper('a4')
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans'])
            ->output();
    }

    /** File name like "garden-gala-attendance-report.pdf". */
    public static function fileName(array $report, string $suffix = 'attendance-report'): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($report['event']['name'] ?? 'event'))), '-') ?: 'event';

        return "{$slug}-{$suffix}";
    }
}
