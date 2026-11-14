// Proof of business an organizer uploads for approval (0057).

export type DocumentType = 'business_permit' | 'registration' | 'bir' | 'valid_id' | 'other'

export interface OrganizationDocument {
	id: string
	doc_type: DocumentType
	original_name: string
	mime_type: string
	size_bytes: number
	uploaded_at: string
}

export const documentTypeLabels: Record<DocumentType, string> = {
	business_permit: 'Business Permit',
	registration: 'DTI or SEC Registration',
	bir: 'BIR Certificate',
	valid_id: 'Valid ID',
	other: 'Other',
}

export const formatFileSize = (bytes: number) => bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`

export const useOrganizationDocuments = () => {
	const api = useApi()
	const base = (orgId: string) => `/organizations/${orgId}/documents`

	return {
		list: async (orgId: string) => (await api.get<OrganizationDocument[]>(base(orgId))).data,
		upload: async (orgId: string, file: File, type: DocumentType) => {
			const data = new FormData()
			data.append('file', file)
			data.append('doc_type', type)
			return (await api.post<OrganizationDocument>(base(orgId), data)).data
		},
		remove: (orgId: string, id: string) => api.delete(`${base(orgId)}/${id}`),
		file: (orgId: string, id: string) => api.blob(`${base(orgId)}/${id}/file`),
	}
}
