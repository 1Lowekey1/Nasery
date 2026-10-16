// The menu a team member (staff, not the owner) sees: only the pages their
// role and their event assignments let them use. The server checks every
// action again; this only keeps them from landing on "not allowed" pages.
//
// Permissions come from organizations/mine → my_membership: `permissions`
// (organization-wide, from the role) and `event_permissions` (only on events
// they're assigned to, e.g. check-in staff get checkin.perform there).

export interface TeamNavItem { label: string, to: string, icon: string, matches?: string[] }

const items: (TeamNavItem & { needs: string[] | null })[] = [
	{ label: 'My Assignments', to: '/team/dashboard', icon: 'clipboard-list', needs: null },
	{ label: 'QR Check-in', to: '/organizer/ticketattendance/qr-checkin', icon: 'check-circle', needs: ['checkin.perform'] },
	{ label: 'Guest List', to: '/organizer/ticketattendance/attendees', icon: 'users', needs: ['attendee.manage', 'ticket.manage'], matches: ['/organizer/ticketattendance/attendees', '/organizer/ticketattendance/walkin-counter'] },
	{ label: 'Events', to: '/organizer/eventmanagement/all-events', icon: 'calendar', needs: ['event.create', 'event.update', 'checklist.manage', 'task.manage'], matches: ['/organizer/eventmanagement'] },
	{ label: 'Activity Timeline', to: '/organizer/activity-timeline', icon: 'clipboard-list', needs: ['event.update', 'checklist.manage', 'task.manage'] },
	{ label: 'Inquiries & Quotations', to: '/organizer/inquiries/match-inquiries', icon: 'file-text', needs: ['inquiry.manage', 'quotation.manage'], matches: ['/organizer/inquiries'] },
	{ label: 'Payments', to: '/organizer/ticketattendance/payments', icon: 'peso', needs: ['finance.view'] },
	{ label: 'Earnings', to: '/organizer/earnings', icon: 'peso', needs: ['finance.view'] },
	{ label: 'Team', to: '/organizer/team', icon: 'user-plus', needs: ['staff.manage'] },
]

export function useTeamNav() {
	const { organization } = useOrganization()

	const granted = computed(() => {
		const m = organization.value?.my_membership
		return new Set([...(m?.permissions ?? []), ...(m?.event_permissions ?? [])])
	})

	const nav = computed<TeamNavItem[]>(() => items
		.filter(i => i.needs === null || i.needs.some(p => granted.value.has(p)))
		.map(({ needs: _needs, ...item }) => item))

	return { nav, can: (permission: string) => granted.value.has(permission) }
}
