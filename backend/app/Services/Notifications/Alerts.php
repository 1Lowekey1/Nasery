<?php

namespace App\Services\Notifications;

use App\Models\Event;
use App\Models\Inquiry;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Task;
use App\Services\CheckIn\WalkInDesk;
use App\Services\Tickets\TicketMailer;
use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What NaSeRy tells whom (B13). Domain services call these after their
 * change; each picks recipients, wording and the page to open. Failures to
 * notify never undo the change itself: notifications are side effects.
 */
class Alerts
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly Recipients $recipients,
        private readonly TicketMailer $tickets,
        private readonly DatabaseContext $db,
        private readonly GuestMail $guestMail,
    ) {}

    /** @param  Collection<int, string>|list<string>  $organizationIds */
    public function inquiryReceived(Inquiry $inquiry, Collection|array $organizationIds): void
    {
        $info = $this->db->system('alerts.inquiry', fn () => DB::table('inquiries as i')->leftJoin('event_types as t', 't.id', '=', 'i.event_type_id')
            ->where('i.id', $inquiry->id)->first(['t.name as type', 'i.guest_count', 'i.requested_date', 'i.location_text']));
        $what = trim(($info->type ?? 'Event').' for '.($info->guest_count ?? '?').' guests');
        $when = $info->requested_date ? ' on '.CarbonImmutable::parse($info->requested_date)->format('M j, Y') : '';

        foreach (collect($organizationIds)->unique() as $orgId) {
            $this->notifier->send($this->recipients->forOrganization($orgId, ['inquiry.manage']), 'inquiry.received',
                "New inquiry: {$what}", trim("{$what}{$when}".($info->location_text ? " · {$info->location_text}" : '')).'. Send a quotation if you can take it.',
                ['inquiry_id' => $inquiry->id], '/organizer/inquiries/match-inquiries', "inquiry:{$inquiry->id}:{$orgId}");
        }
    }

    /**
     * The client changed a sent request (0051).
     *
     * @param  Collection<int, string>  $organizationIds  invited, not declined or withdrawn
     * @param  list<string>  $changes  e.g. ['the date', 'the budget']
     * @param  list<string>  $quoted  of those, the ones with a quotation out (opens Offers)
     */
    public function inquiryRevised(Inquiry $inquiry, Collection $organizationIds, array $changes, array $quoted = []): void
    {
        $what = $changes === [] ? 'the details' : (count($changes) > 1
            ? implode(', ', array_slice($changes, 0, -1)).' and '.end($changes)
            : $changes[0]);

        foreach ($organizationIds->unique() as $orgId) {
            $this->notifier->send($this->recipients->forOrganization($orgId, ['inquiry.manage']), 'inquiry.revised',
                'A client updated their request', "The client changed {$what}. Check the request, and revise your quotation if it no longer fits.",
                ['inquiry_id' => $inquiry->id], in_array($orgId, $quoted, true) ? '/organizer/inquiries/offers' : '/organizer/inquiries/match-inquiries',
                "inquiry-revised:{$inquiry->id}:{$orgId}:{$inquiry->revision_count}");
        }
    }

    public function quotationReceived(Quotation $quotation, bool $revised = false): void
    {
        $row = $this->quotationInfo($quotation);
        $amount = '₱'.number_format((float) $row->total_amount, 2);
        // Mock defense: say so when an offer fits the client's budget, or is close to it.
        $fit = self::budgetFit((float) $row->total_amount, $row->budget_min, $row->budget_max);
        $title = match ($fit) {
            'within' => "An offer within your budget from {$row->organizer}",
            'close' => "An offer close to your budget from {$row->organizer}",
            default => ($revised ? 'Revised quotation from ' : 'New quotation from ').$row->organizer,
        };
        $budget = $row->budget_max !== null ? ' Your budget: up to ₱'.number_format((float) $row->budget_max, 0).'.' : '';
        $this->notifier->send($row->client_user_id, 'quotation.received', $title,
            "{$row->organizer} ".($revised ? 'revised their offer to ' : 'quoted ')."{$amount} for your request.".($fit ? $budget : '').' Compare offers and accept the one you like.',
            ['quotation_id' => $quotation->id, 'inquiry_id' => $row->inquiry_id], "/client/my-events?offers={$row->inquiry_id}");
    }

    public function quotationDecided(Quotation $quotation, bool $accepted, ?string $reason = null): void
    {
        $row = $this->quotationInfo($quotation);
        $this->notifier->send($this->recipients->forOrganization($row->organization_id, ['quotation.manage']),
            $accepted ? 'quotation.accepted' : 'quotation.declined',
            $accepted ? "{$row->client} accepted your quotation" : "{$row->client} declined your quotation",
            $accepted
                ? 'Congratulations! Create the event from the accepted quotation to start planning.'
                : 'Reason: '.($reason ?: 'not given').'.',
            ['quotation_id' => $quotation->id, 'inquiry_id' => $row->inquiry_id], '/organizer/inquiries/offers');
    }

    public function eventCreated(Event $event): void
    {
        $client = $this->db->system('alerts.client', fn () => DB::table('inquiries')->where('id', $event->inquiry_id)->value('client_user_id'));
        $this->notifier->send($client, 'event.created', "Your event is set up: {$event->name}",
            'Your organizer created the event. Follow its preparation and timeline in NaSeRy.',
            ['event_id' => $event->id], "/client/event-details?event={$event->id}");
    }

    public function reportShared(Event $event, int $version): void
    {
        $client = $this->db->system('alerts.report_client', fn () => DB::table('inquiries')->where('id', $event->inquiry_id)->value('client_user_id'));
        $this->notifier->send($client, 'report.shared', $version > 1 ? "Updated report: {$event->name}" : "Your post-event report: {$event->name}",
            'Your organizer shared the attendance report for your event: who came, walk-ins and the arrival timeline.',
            ['event_id' => $event->id, 'version' => $version], "/client/event-report?event={$event->id}");
    }

    /** Event completed: invite the client (the "bid reference" holder) to rate the organizer. */
    public function reviewRequested(Event $event): void
    {
        if ($event->inquiry_id === null) {
            return;
        }
        [$client, $organizer] = $this->db->system('alerts.review_request', fn () => [
            DB::table('inquiries')->where('id', $event->inquiry_id)->value('client_user_id'),
            DB::table('organizations')->where('id', $event->organization_id)->value('display_name'),
        ]);
        $this->notifier->send($client, 'review.requested', "How was {$event->name}?",
            "Rate {$organizer} and help other clients choose. It takes a minute.", ['event_id' => $event->id],
            "/client/event-details?event={$event->id}#review", "review-request:{$event->id}");
    }

    public function reviewReceived(Event $event, int $rating): void
    {
        $this->notifier->send($this->recipients->forOrganization($event->organization_id, ['quotation.manage']), 'review.received',
            "New {$rating}-star review", "Your client reviewed {$event->name}. You can reply publicly from Reviews.",
            ['event_id' => $event->id, 'rating' => $rating], '/organizer/reviews');
    }

    public function reviewReplied(string $clientUserId, string $organizer, string $eventId): void
    {
        $this->notifier->send($clientUserId, 'review.replied', "{$organizer} replied to your review", null,
            ['event_id' => $eventId], "/client/event-details?event={$eventId}#review");
    }

    public function staffInvited(OrganizationMember $member, string $organizationName): void
    {
        $this->notifier->send($member->user_id, 'staff.invited', "{$organizationName} invited you to their team",
            'Open NaSeRy to accept or decline the invitation.', ['organization_member_id' => $member->id], '/organizer/onboarding');
    }

    /** Owner (or a staff manager) removed this member; their account stays, the team access ends. */
    public function staffRemoved(OrganizationMember $member, string $organizationName): void
    {
        $this->notifier->send($member->user_id, 'staff.removed', "You're no longer on {$organizationName}'s team",
            "{$organizationName} removed you from their team on NaSeRy, so you can't open their events anymore. Your account stays; another organizer can invite you.",
            ['organization_member_id' => $member->id], '/organizer/onboarding');
    }

    /** A member left on their own: tell whoever manages the team. */
    public function staffLeft(OrganizationMember $member, string $name, int $openTasks): void
    {
        $this->notifier->send($this->recipients->forOrganization($member->organization_id, ['staff.manage']), 'staff.left', "{$name} left your team",
            $openTasks > 0 ? "{$openTasks} of their open ".($openTasks === 1 ? 'task needs' : 'tasks need').' a new assignee in Preparation.' : 'They had no open tasks.',
            ['organization_member_id' => $member->id, 'organization_id' => $member->organization_id], '/organizer/team');
    }

    /** Open tasks of a removed member were handed to this person. */
    public function tasksHandedOver(OrganizationMember $to, int $count): void
    {
        $this->notifier->send($to->user_id, 'task.assigned', $count === 1 ? 'A task was handed over to you' : "{$count} tasks were handed over to you",
            'A teammate left, so their open work is now yours. See My Assignments.', ['organization_member_id' => $to->id], '/team/dashboard');
    }

    /** @param  list<string>  $eventMemberIds  newly assigned */
    public function taskAssigned(Event $event, Task $task, array $eventMemberIds, ?string $actorUserId): void
    {
        $users = $this->db->system('alerts.assignees', fn () => DB::table('event_members as em')->join('organization_members as m', 'm.id', '=', 'em.organization_member_id')
            ->whereIn('em.id', $eventMemberIds)->pluck('m.user_id')->all());
        $this->notifier->send(array_values(array_diff($users, [$actorUserId])), 'task.assigned', "New task: {$task->name}",
            "You were assigned a task for {$event->name}.", ['event_id' => $event->id, 'task_id' => $task->id], '/organizer/my-tasks');
    }

    /** 0044: the organizer reviewed a finished task; tell its assignees. */
    public function taskReviewed(Event $event, Task $task, bool $verified, ?string $note, ?string $actorUserId): void
    {
        $users = $this->db->system('alerts.task_reviewers', fn () => DB::table('task_assignees as ta')->join('event_members as em', 'em.id', '=', 'ta.event_member_id')
            ->join('organization_members as m', 'm.id', '=', 'em.organization_member_id')->where('ta.task_id', $task->id)->pluck('m.user_id')->all());
        $this->notifier->send(array_values(array_diff($users, [$actorUserId])), 'task.reviewed',
            $verified ? "Verified: {$task->name}" : "Changes requested: {$task->name}",
            $verified ? "Your task for {$event->name} was checked and verified." : 'Please take another look'.($note ? ': '.rtrim($note, '.').'.' : '.'),
            ['event_id' => $event->id, 'task_id' => $task->id], '/organizer/my-tasks');
    }

    /**
     * New valid tickets: e-mail them (QR + PDF) and tell the account holder in-app.
     *
     * @param  list<string>  $ticketIds
     */
    public function ticketsIssued(array $ticketIds, ?string $purchaserUserId, string $eventName): void
    {
        $this->tickets->send($ticketIds);

        if ($purchaserUserId !== null) {
            $n = count($ticketIds);
            $this->notifier->send($purchaserUserId, 'tickets.issued', $n > 1 ? "Your {$n} tickets for {$eventName}" : "Your ticket for {$eventName}",
                'Show the QR code at the entrance. It was also e-mailed to you.', ['ticket_ids' => $ticketIds], '/my-tickets');
        }
    }

    public function refunded(Payment $payment, float $amount, bool $ticketsCancelled): void
    {
        $row = $this->db->system('alerts.refund', fn () => DB::table('registrations as r')->join('events as e', 'e.id', '=', 'r.event_id')
            ->where('r.id', $payment->registration_id)->first(['r.purchaser_user_id', 'r.purchaser_email', 'r.purchaser_name', 'e.name']));
        $title = '₱'.number_format($amount, 2)." refunded for {$row?->name}";
        $body = ($ticketsCancelled ? 'Your tickets were cancelled. ' : '').'The money goes back to the payment method you used; banks and e-wallets can take a few days.';
        if ($row !== null && $row->purchaser_user_id === null) {
            // Bought without an account: e-mail is the only way to tell them.
            $this->guestMail->send($row->purchaser_email, $row->purchaser_name, $title, $body);

            return;
        }
        $this->notifier->send($row?->purchaser_user_id, 'payment.refunded', $title, $body, ['payment_id' => $payment->id], '/my-tickets');
    }

    /** @param  list<string>  $ticketIds */
    public function ticketsCancelled(array $ticketIds, string $reason): void
    {
        $rows = $this->db->system('alerts.cancelled', fn () => DB::table('tickets as t')->join('registrations as r', 'r.id', '=', 't.registration_id')
            ->join('events as e', 'e.id', '=', 't.event_id')->whereIn('t.id', $ticketIds)->whereNotNull('r.purchaser_user_id')
            ->get(['r.purchaser_user_id', 'e.name']));

        foreach ($rows->groupBy('purchaser_user_id') as $userId => $group) {
            $this->notifier->send($userId, 'ticket.cancelled', "Ticket cancelled: {$group->first()->name}",
                "The organizer cancelled {$group->count()} ticket".($group->count() > 1 ? 's' : '')." of yours. Reason: {$reason}.", [], '/my-tickets');
        }

        // Buyers without an account: by e-mail.
        $guests = $this->db->system('alerts.cancelled_guests', fn () => DB::table('tickets as t')->join('registrations as r', 'r.id', '=', 't.registration_id')
            ->join('events as e', 'e.id', '=', 't.event_id')->whereIn('t.id', $ticketIds)->whereNull('r.purchaser_user_id')->whereNotNull('r.purchaser_email')
            ->get(['r.purchaser_email', 'r.purchaser_name', 'e.name']));
        foreach ($guests->groupBy(fn ($g) => strtolower(trim((string) $g->purchaser_email))) as $group) {
            $this->guestMail->send($group->first()->purchaser_email, $group->first()->purchaser_name, "Ticket cancelled: {$group->first()->name}",
                "The organizer cancelled {$group->count()} ticket".($group->count() > 1 ? 's' : '')." of yours. Reason: {$reason}. If you paid, contact the organizer about a refund.");
        }
    }

    /** @param  list<string>  $ticketIds  tickets the cancellation voided */
    public function eventCancelled(Event $event, array $ticketIds, ?string $reason): void
    {
        $users = $this->db->system('alerts.event_cancelled', function () use ($event, $ticketIds) {
            $holders = DB::table('tickets as t')->join('registrations as r', 'r.id', '=', 't.registration_id')
                ->whereIn('t.id', $ticketIds)->whereNotNull('r.purchaser_user_id')->pluck('r.purchaser_user_id');
            $client = $event->inquiry_id ? DB::table('inquiries')->where('id', $event->inquiry_id)->value('client_user_id') : null;

            return $holders->push($client)->filter()->unique()->values()->all();
        });

        $body = 'The organizer cancelled this event'.($reason ? ". Reason: {$reason}" : '').'. Tickets are no longer valid; paid tickets are refunded by the organizer.';
        $this->notifier->send($users, 'event.cancelled', "Cancelled: {$event->name}", $body, ['event_id' => $event->id], '/my-tickets');

        // Buyers without an account: by e-mail, once per address.
        $guests = $this->db->system('alerts.event_cancelled_guests', fn () => DB::table('tickets as t')->join('registrations as r', 'r.id', '=', 't.registration_id')
            ->whereIn('t.id', $ticketIds)->whereNull('r.purchaser_user_id')->whereNotNull('r.purchaser_email')
            ->get(['r.purchaser_email', 'r.purchaser_name']));
        foreach ($guests->unique(fn ($g) => strtolower(trim((string) $g->purchaser_email))) as $g) {
            $this->guestMail->send($g->purchaser_email, $g->purchaser_name, "Cancelled: {$event->name}", $body);
        }
    }

    /** Almost full / full, once per level per event, to the door team and managers. */
    public function capacity(Event $event): void
    {
        // System context: a buyer's RLS view can't see everyone's tickets.
        $state = $this->db->system('alerts.capacity', fn () => app(WalkInDesk::class)->capacityState($event));
        $level = match ($state['level']) {
            'warning' => 'warning',
            'full', 'over' => 'full',
            default => null,
        };

        if ($level === null) {
            return;
        }

        $users = $this->recipients->forEvent($event, ['checkin.perform', 'event.update']);
        $this->notifier->send($users, "capacity.{$level}",
            $level === 'full' ? "{$event->name} is full" : "{$event->name} is almost full",
            $level === 'full'
                ? "All {$state['limit']} places are taken. Door staff can't admit more; a manager can admit past capacity with a reason."
                : "{$state['used']} of {$state['limit']} places are taken ({$state['remaining']} left).",
            ['event_id' => $event->id, 'used' => $state['used'], 'limit' => $state['limit']],
            "/organizer/ticketattendance/walkin-counter?event={$event->id}", "capacity:{$event->id}:{$level}");
    }

    /**
     * within: inside the client's range; close: at most 10% over their maximum
     * (or 10% under their minimum). Null when there's no budget or it's far off.
     */
    public static function budgetFit(float $total, mixed $min, mixed $max): ?string
    {
        if ($max === null && $min === null) {
            return null;
        }
        $min = $min !== null ? (float) $min : 0.0;
        $max = $max !== null ? (float) $max : null;
        if ($total >= $min && ($max === null || $total <= $max)) {
            return 'within';
        }
        if (($max !== null && $total > $max && $total <= $max * 1.10) || ($total < $min && $total >= $min * 0.90)) {
            return 'close';
        }

        return null;
    }

    private function quotationInfo(Quotation $quotation): object
    {
        return $this->db->system('alerts.quotation', fn () => DB::table('quotations as q')
            ->join('inquiries as i', 'i.id', '=', 'q.inquiry_id')
            ->join('organizations as o', 'o.id', '=', 'q.organization_id')
            ->join('users as u', 'u.id', '=', 'i.client_user_id')
            ->where('q.id', $quotation->id)
            ->first(['q.inquiry_id', 'q.organization_id', 'q.total_amount', 'i.client_user_id', 'i.budget_min', 'i.budget_max', 'o.display_name as organizer',
                DB::raw("trim(concat(u.first_name, ' ', u.last_name)) as client")]));
    }
}
