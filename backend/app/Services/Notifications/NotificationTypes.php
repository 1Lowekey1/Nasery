<?php

namespace App\Services\Notifications;

/**
 * Every notification NaSeRy sends. In-app is always on; e-mail can be
 * switched off per type in the user's preferences, except for critical
 * types (0016: critical notifications bypass preferences entirely).
 */
final class NotificationTypes
{
    /** @var array<string, array{label: string, group: string, critical: bool, email: bool}> */
    public const ALL = [
        // Organizers
        'inquiry.received' => ['label' => 'New inquiries for your business', 'group' => 'organizer', 'critical' => false, 'email' => true],
        'quotation.accepted' => ['label' => 'A client accepted your quotation', 'group' => 'organizer', 'critical' => false, 'email' => true],
        'quotation.declined' => ['label' => 'A client declined your quotation', 'group' => 'organizer', 'critical' => false, 'email' => true],
        'staff.invited' => ['label' => 'Invitations to join an organizer team', 'group' => 'organizer', 'critical' => false, 'email' => true],
        'task.assigned' => ['label' => 'Tasks assigned to you', 'group' => 'organizer', 'critical' => false, 'email' => false],
        'task.reviewed' => ['label' => 'Reviews of tasks you finished', 'group' => 'organizer', 'critical' => false, 'email' => false],
        'capacity.warning' => ['label' => 'An event is almost full', 'group' => 'organizer', 'critical' => false, 'email' => true],
        'capacity.full' => ['label' => 'An event is full', 'group' => 'organizer', 'critical' => true, 'email' => true],
        'team.event_tomorrow' => ['label' => 'Your event is tomorrow', 'group' => 'organizer', 'critical' => false, 'email' => true],
        'review.received' => ['label' => 'A client reviewed your business', 'group' => 'organizer', 'critical' => false, 'email' => true],
        'message.organizer' => ['label' => 'Messages from clients about inquiries', 'group' => 'organizer', 'critical' => false, 'email' => true],
        'access.requested' => ['label' => 'NaSeRy asks to view your workspace', 'group' => 'organizer', 'critical' => true, 'email' => true],
        'access.update' => ['label' => 'NaSeRy support access started or ended', 'group' => 'organizer', 'critical' => false, 'email' => true],
        'billing.update' => ['label' => 'Your plan, payments and renewals', 'group' => 'organizer', 'critical' => true, 'email' => true],
        'payout.update' => ['label' => 'Payouts of your ticket earnings', 'group' => 'organizer', 'critical' => true, 'email' => true],
        'refund.claim_reviewed' => ['label' => 'NaSeRy reviewed a refund fee claim', 'group' => 'organizer', 'critical' => true, 'email' => true],
        // Clients
        'quotation.received' => ['label' => 'Quotations from organizers', 'group' => 'client', 'critical' => false, 'email' => true],
        'event.created' => ['label' => 'Your organizer set up your event', 'group' => 'client', 'critical' => false, 'email' => true],
        'report.shared' => ['label' => 'Your post-event report is ready', 'group' => 'client', 'critical' => false, 'email' => true],
        'message.client' => ['label' => 'Messages from organizers about your inquiries', 'group' => 'client', 'critical' => false, 'email' => true],
        'review.requested' => ['label' => 'Invitations to review your organizer', 'group' => 'client', 'critical' => false, 'email' => true],
        'review.replied' => ['label' => 'Your organizer replied to your review', 'group' => 'client', 'critical' => false, 'email' => true],
        // Everyone with tickets / events
        'tickets.issued' => ['label' => 'Your tickets are ready', 'group' => 'tickets', 'critical' => false, 'email' => false],
        'payment.refunded' => ['label' => 'Refunds', 'group' => 'tickets', 'critical' => true, 'email' => true],
        'ticket.cancelled' => ['label' => 'A ticket of yours was cancelled', 'group' => 'tickets', 'critical' => true, 'email' => true],
        'event.cancelled' => ['label' => 'An event you are part of was cancelled', 'group' => 'tickets', 'critical' => true, 'email' => true],
        // Anyone: moderation outcomes and account notices from NaSeRy
        'review.moderated' => ['label' => 'Decisions on reviews you wrote, received or reported', 'group' => 'account', 'critical' => true, 'email' => true],
        'account.notice' => ['label' => 'Changes to your account by NaSeRy', 'group' => 'account', 'critical' => true, 'email' => true],
        // Platform admins
        'admin.application' => ['label' => 'Organizer applications to review', 'group' => 'admin', 'critical' => false, 'email' => true],
        'admin.report' => ['label' => 'Reported reviews', 'group' => 'admin', 'critical' => false, 'email' => true],
        'admin.access' => ['label' => 'Workspace access answers and support invites', 'group' => 'admin', 'critical' => false, 'email' => true],
    ];

    public static function exists(string $type): bool
    {
        return isset(self::ALL[$type]);
    }

    public static function critical(string $type): bool
    {
        return self::ALL[$type]['critical'] ?? false;
    }

    /** Default e-mail setting when the user hasn't chosen (ticket e-mails carry the QR themselves). */
    public static function emailByDefault(string $type): bool
    {
        return self::ALL[$type]['email'] ?? false;
    }
}
