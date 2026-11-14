// Backing out of an accepted booking (0060). Same calls for both sides; only the path differs.

export interface BookingCancellationState {
	cancellation: {
		id: string
		status: 'pending' | 'agreed' | 'auto_agreed' | 'declined' | 'withdrawn'
		requested_by_side: 'client' | 'organizer'
		reason: string
		respond_by: string
		response_note: string | null
		decided_at: string | null
		can_respond: boolean
		can_withdraw: boolean
	} | null
	can_request: boolean
	grace_hours: number
}

export const useBookingCancellation = (inquiryId: string, organizationId?: string | null) => {
	const api = useApi()
	const path = organizationId
		? `/organizations/${organizationId}/inquiries/${inquiryId}/cancellation`
		: `/inquiries/${inquiryId}/cancellation`

	return {
		state: async () => (await api.get<BookingCancellationState>(path)).data,
		request: async (reason: string) => (await api.post<BookingCancellationState>(path, { reason })).data,
		respond: async (decision: 'agree' | 'decline', note: string | null) =>
			(await api.post<BookingCancellationState>(`${path}/respond`, { decision, note })).data,
		withdraw: async () => (await api.delete<BookingCancellationState>(path)).data,
	}
}
