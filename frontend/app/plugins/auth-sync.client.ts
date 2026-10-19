// One browser = one sign-in cookie shared by all its tabs. When another tab
// signs in as someone else (or signs out), this tab reloads so it never shows
// one account while the server is acting as another. Profile edits or e-mail
// verification of the same account don't reload anything. Unfinished form
// drafts are saved before the reload (useDraft) and come back afterwards.
export default defineNuxtPlugin(() => {
    window.addEventListener('storage', (event) => {
        if (event.key !== 'auth_session_changed' || !event.newValue) return

        let changedTo: string | null = null
        try {
            changedTo = (JSON.parse(event.newValue) as { userId: string | null }).userId ?? null
        } catch {
            return
        }

        const { user } = useAuth()
        if ((user.value?.id ?? null) === changedTo) return // same person: nothing to do

        window.location.reload()
    })
})
