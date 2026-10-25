// Uploaded pictures (server: MediaController + ImageStore). Every upload is
// re-encoded by the server; these calls only send the file.

export const imageRules = {
	/** Same limits as the server (config nasery.media). */
	maxBytes: 5 * 1024 * 1024,
	types: ['image/jpeg', 'image/png', 'image/webp'],
	accept: 'image/jpeg,image/png,image/webp',
}

/** Quick check before uploading (the server checks the real content again). */
export function imageProblem(file: File): string {
	if (!imageRules.types.includes(file.type)) return 'Choose a JPG, PNG or WebP image.'
	if (file.size > imageRules.maxBytes) return 'Choose an image under 5 MB.'
	return ''
}

export const useMedia = () => {
	const api = useApi()
	const form = (file: File) => {
		const data = new FormData()
		data.append('image', file)
		return data
	}

	return {
		/** Profile photo of the signed-in user; returns their updated account. */
		uploadAvatar: async (file: File) => (await api.post<{ avatar_url: string | null }>('/auth/me/avatar', form(file))).data,
		removeAvatar: async () => (await api.delete<{ avatar_url: string | null }>('/auth/me/avatar')).data,
		uploadOrgImage: async (orgId: string, slot: 'logo' | 'cover', file: File) =>
			(await api.post<{ url: string | null }>(`/organizations/${orgId}/profile/${slot}`, form(file))).data.url,
		removeOrgImage: async (orgId: string, slot: 'logo' | 'cover') =>
			(await api.delete<{ url: string | null }>(`/organizations/${orgId}/profile/${slot}`)).data.url,
		uploadEventCover: async (orgId: string, eventId: string, file: File) =>
			(await api.post<{ url: string | null }>(`/organizations/${orgId}/events/${eventId}/cover`, form(file))).data.url,
		removeEventCover: async (orgId: string, eventId: string) =>
			(await api.delete<{ url: string | null }>(`/organizations/${orgId}/events/${eventId}/cover`)).data.url,
	}
}

// Pictures picked inside a form wait for that form's own Save button.
// A page form calls provideMediaBatch(); a layout's profile panel calls
// createMediaBatch() and wraps the panel in <MediaBatchScope :batch> so the
// pages under that layout aren't affected. On Save the form calls flush().
// Uploaders outside such a form (super admin menu, event page) upload on pick.
export interface PendingPicture {
	file: File
	/** The file as picked, before cropping (for Adjust). */
	original?: File
	preview: string
	upload: (file: File) => Promise<string | null | void>
	done: (url: string | null) => void
	error: string
}

export interface MediaBatch {
	pending: Ref<Record<string, PendingPicture>>
	count: ComputedRef<number>
	put: (slot: string, picture: Omit<PendingPicture, 'error'>) => void
	drop: (slot: string) => void
	clear: () => void
	/** Uploads every waiting picture; returns the first problem, or ''. Failed ones stay waiting. */
	flush: () => Promise<string>
}

export const mediaBatchKey: InjectionKey<MediaBatch> = Symbol('media-batch')

export function provideMediaBatch(): MediaBatch {
	const batch = createMediaBatch()
	provide(mediaBatchKey, batch)
	return batch
}

export function createMediaBatch(): MediaBatch {
	const pending = ref<Record<string, PendingPicture>>({})
	const drop = (slot: string) => {
		const p = pending.value[slot]
		if (!p) return
		URL.revokeObjectURL(p.preview)
		const { [slot]: _, ...rest } = pending.value
		pending.value = rest
	}
	const batch: MediaBatch = {
		pending,
		count: computed(() => Object.keys(pending.value).length),
		put(slot, picture) {
			drop(slot)
			pending.value = { ...pending.value, [slot]: { ...picture, error: '' } }
		},
		drop,
		clear: () => Object.keys(pending.value).forEach(drop),
		async flush() {
			let problem = ''
			for (const [slot, p] of Object.entries(pending.value)) {
				try {
					const url = await p.upload(p.file)
					drop(slot)
					p.done(typeof url === 'string' ? url : null)
				} catch (e) {
					p.error = Object.values(fieldErrors(e))[0] ?? authMessage(e)
					pending.value = { ...pending.value }
					problem ||= `A picture could not be saved: ${p.error}`
				}
			}
			return problem
		},
	}
	onBeforeUnmount(batch.clear)
	return batch
}

export const useMediaBatch = () => inject(mediaBatchKey, null)
