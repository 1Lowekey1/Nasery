<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Has anything changed for me?" (mock defense, live updates option B). Pages
 * ask every few seconds and reload their data only when this stamp changes.
 * It is the latest moment something happened that this person can see: their
 * notifications and conversations, and for organizers their organization's
 * logged actions, ticket sales, check-ins and walk-ins. Cheap: a few indexed
 * max() reads, no rows sent.
 */
class ChangeStampController extends Controller
{
    public function __construct(private readonly DatabaseContext $db) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $stamp = $this->db->system('live.stamp', function () use ($user) {
            $times = [
                DB::table('notifications')->where('recipient_user_id', $user->id)->max('created_at'),
                DB::table('notifications')->where('recipient_user_id', $user->id)->max('read_at'),
            ];

            $orgIds = DB::table('organization_members')->where('user_id', $user->id)->where('status', 'active')
                ->whereNull('deleted_at')->pluck('organization_id');

            if ($user->account_type === AccountType::Admin) {
                $times[] = DB::table('audit_logs')->max('created_at');
            } elseif ($orgIds->isNotEmpty()) {
                $events = DB::table('events')->whereIn('organization_id', $orgIds)->select('id');
                $times[] = DB::table('audit_logs')->whereIn('organization_id', $orgIds)->max('created_at');
                $times[] = DB::table('attendance_records')->whereIn('event_id', $events)->max('created_at');
                $times[] = DB::table('walk_in_records')->whereIn('event_id', $events)->max('created_at');
                $times[] = DB::table('tickets')->whereIn('event_id', $events)->max('updated_at');
                $times[] = DB::table('inquiry_messages as m')->join('inquiry_organizations as io', 'io.id', '=', 'm.inquiry_organization_id')
                    ->whereIn('io.organization_id', $orgIds)->max('m.created_at');
            }

            // As a client: their requests' offers and conversations.
            $inquiries = DB::table('inquiries')->where('client_user_id', $user->id)->select('id');
            $times[] = DB::table('quotations')->whereIn('inquiry_id', $inquiries)->max('updated_at');
            $times[] = DB::table('inquiry_messages as m')->join('inquiry_organizations as io', 'io.id', '=', 'm.inquiry_organization_id')
                ->whereIn('io.inquiry_id', $inquiries)->max('m.created_at');

            return substr(md5(implode('|', array_map(fn ($t) => (string) $t, $times))), 0, 16);
        });

        return ApiResponse::success(['stamp' => $stamp]);
    }
}
