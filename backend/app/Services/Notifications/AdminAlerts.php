<?php

namespace App\Services\Notifications;

use App\Enums\AccountType;
use App\Enums\MembershipStatus;
use App\Support\Database\DatabaseContext;
use Illuminate\Support\Facades\DB;

/**
 * What NaSeRy's admin side tells whom (B17): access consent, moderation
 * outcomes, fee-claim reviews, account notices, and the admin inbox.
 * Like Alerts, these are side effects that never undo the change.
 */
class AdminAlerts
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly DatabaseContext $db,
    ) {}

    // ---- Admin inbox -------------------------------------------------------

    public function applicationSubmitted(string $organizationId, string $name, bool $resubmitted): void
    {
        $this->notifier->send($this->admins(), 'admin.application',
            ($resubmitted ? 'Application resubmitted: ' : 'New organizer application: ').$name,
            'Review the business profile and decide from Organizers.',
            ['organization_id' => $organizationId], "/admin/organizers/{$organizationId}");
    }

    public function reviewReported(string $reviewId, string $organizer): void
    {
        $this->notifier->send($this->admins(), 'admin.report', "A review of {$organizer} was reported",
            'Open Moderation to look into it.', ['review_id' => $reviewId], '/admin/moderation', "report:{$reviewId}:".now()->format('YmdH'));
    }

    // ---- Workspace access (consent) -----------------------------------------

    public function accessRequested(string $organizationId, string $reason, int $hours): void
    {
        $this->notifier->send($this->owners($organizationId), 'access.requested', 'NaSeRy asks to view your workspace',
            'Reason: '.self::sentence($reason)." If you approve, an administrator can view (not change) your events, team, payments and reviews for {$hours} hours. You can refuse or end it anytime.",
            ['organization_id' => $organizationId], '/organizer/nasery-access');
    }

    public function accessAnswered(string $adminUserId, string $organizer, bool $approved, ?string $note, ?string $until): void
    {
        $this->notifier->send($adminUserId, 'admin.access', $approved ? "{$organizer} approved your access request" : "{$organizer} refused your access request",
            $approved ? "You can view their workspace until {$until}." : 'Note: '.self::sentence($note ?: 'none given'),
            [], '/admin/access');
    }

    public function supportInvited(string $organizationId, string $organizer, string $reason): void
    {
        $this->notifier->send($this->admins(), 'admin.access', "{$organizer} invited NaSeRy support",
            'Reason: '.self::sentence($reason).' Accept it to get read-only access to their workspace.', ['organization_id' => $organizationId], '/admin/access');
    }

    public function accessStarted(string $organizationId, string $adminName, string $until): void
    {
        $this->notifier->send($this->owners($organizationId), 'access.update', "{$adminName} from NaSeRy is looking into your workspace",
            "Read-only access until {$until}. End it anytime from NaSeRy Access.", ['organization_id' => $organizationId], '/organizer/nasery-access');
    }

    public function accessEnded(string $organizationId, ?string $adminUserId, string $by): void
    {
        $this->notifier->send($this->owners($organizationId), 'access.update', 'NaSeRy access ended', "Ended by {$by}.",
            ['organization_id' => $organizationId], '/organizer/nasery-access');
        if ($adminUserId) {
            $this->notifier->send($adminUserId, 'admin.access', 'Workspace access ended', "Ended by {$by}.", [], '/admin/access');
        }
    }

    // ---- Moderation ---------------------------------------------------------

    /**
     * @param  'reviewing'|'kept'|'hidden'|'restored'  $outcome
     * @param  list<string>  $reporters
     */
    public function reviewModerated(object $review, string $outcome, ?string $reason, array $reporters): void
    {
        $organizer = $review->organizer ?? 'the organizer';
        $why = $reason ? ' Reason: '.self::sentence($reason) : '';
        [$clientTitle, $clientBody, $orgTitle, $orgBody] = match ($outcome) {
            'reviewing' => ["Your review of {$organizer} is under review", 'It was reported. It is hidden while NaSeRy looks into it; you will hear back.',
                'A review of yours is under review', 'It was reported and is hidden while NaSeRy looks into it.'],
            'kept' => ["Your review of {$organizer} is back", 'NaSeRy looked into a report and kept your review.'.$why,
                'Reported review kept', 'NaSeRy looked into the report and kept the review.'.$why],
            'hidden' => ["Your review of {$organizer} was hidden", 'NaSeRy hid it after a report.'.$why.' Contact NaSeRy if you disagree.',
                'Reported review hidden', 'NaSeRy hid the review. It no longer shows or counts in your rating.'.$why],
            'restored' => ["Your review of {$organizer} is visible again", 'NaSeRy restored your review.'.$why,
                'A hidden review was restored', 'NaSeRy restored a review; it shows and counts again.'.$why],
        };
        $data = ['review_id' => $review->id, 'event_id' => $review->event_id, 'outcome' => $outcome];

        $this->notifier->send($review->client_user_id, 'review.moderated', $clientTitle, $clientBody, $data, "/client/event-details?event={$review->event_id}#review");
        $this->notifier->send($this->owners($review->organization_id), 'review.moderated', $orgTitle, $orgBody, $data, '/organizer/reviews');

        if (in_array($outcome, ['kept', 'hidden'], true)) {
            $others = array_values(array_diff($reporters, [$review->client_user_id], $this->owners($review->organization_id)));
            $this->notifier->send($others, 'review.moderated', 'Thanks for your report',
                $outcome === 'hidden' ? 'NaSeRy hid the review you reported.' : 'NaSeRy looked into the review you reported and kept it.'.$why, $data);
        }
    }

    public function feeClaimReviewed(string $organizationId, string $eventName, string $amount, bool $accepted, ?string $note): void
    {
        $this->notifier->send($this->owners($organizationId), 'refund.claim_reviewed',
            $accepted ? "Refund claim accepted: {$eventName}" : "Refund claim flagged: {$eventName}",
            $accepted
                ? "NaSeRy accepted the reason for your ₱{$amount} refund; the platform fee return stands."
                : "NaSeRy flagged the reason given for your ₱{$amount} refund. Note: ".self::sentence($note ?: 'none').' Repeated misuse can lead to suspension.',
            [], '/organizer/ticketattendance/payments');
    }

    /** B18: plan and renewal news for the organization's owners. */
    public function billingUpdate(string $organizationId, string $title, string $body): void
    {
        $this->notifier->send($this->owners($organizationId), 'billing.update', $title, $body, ['organization_id' => $organizationId], '/organizer/billing');
    }

    public function accountStatus(string $userId, bool $suspended, string $reason): void
    {
        $this->notifier->send($userId, 'account.notice', $suspended ? 'Your NaSeRy account was suspended' : 'Your NaSeRy account is active again',
            'Reason: '.self::sentence($reason).($suspended ? ' Reply to this e-mail or contact NaSeRy support to appeal.' : ' You can sign in again.'), []);
    }

    /** End a free-text reason with exactly one full stop (reasons often bring their own). */
    private static function sentence(string $text): string
    {
        $text = rtrim(trim($text));

        return preg_match('/[.!?]$/u', $text) ? $text : "{$text}.";
    }

    // ---- Recipients ---------------------------------------------------------

    /** @return list<string> active platform admins */
    public function admins(): array
    {
        return $this->db->system('notify.admins', fn () => DB::table('users')->where('account_type', AccountType::Admin->value)
            ->where('status', 'active')->whereNull('deleted_at')->pluck('id')->all());
    }

    /** @return list<string> the organization's active owners */
    public function owners(string $organizationId): array
    {
        return $this->db->system('notify.owners', fn () => DB::table('organization_members')->where('organization_id', $organizationId)
            ->where('is_owner', true)->where('status', MembershipStatus::Active->value)->whereNull('deleted_at')->pluck('user_id')->all());
    }
}
