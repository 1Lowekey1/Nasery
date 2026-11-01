<template>
	<div class="mx-auto max-w-[1400px] space-y-6 pb-8">
		<!-- Crew header -->
		<header class="relative overflow-hidden rounded-3xl bg-primary-800 px-6 py-7 text-white sm:px-8 sm:py-8">
			<div class="tm-hero-dots" aria-hidden="true" />
			<div class="relative flex flex-col gap-7 lg:flex-row lg:items-end lg:justify-between">
				<div class="max-w-xl">
					<h1 class="text-3xl font-extrabold tracking-tight sm:text-4xl">Your event crew</h1>
					<p class="mt-2 text-sm leading-6 text-white/70">Invite the people who help you run events. Each gets a role, and sees the events and tasks you give them.</p>
				</div>
				<div class="flex flex-wrap items-end gap-6 sm:gap-8">
					<div>
						<p class="text-2xl font-extrabold tabular-nums sm:text-3xl">{{ activeMembers.length }}</p>
						<p class="text-xs font-medium text-white/60">active {{ activeMembers.length === 1 ? 'member' : 'members' }}</p>
					</div>
					<div>
						<p class="text-2xl font-extrabold tabular-nums sm:text-3xl">{{ invitedMembers.length }}</p>
						<p class="text-xs font-medium text-white/60">waiting to join</p>
					</div>
					<div>
						<p class="text-2xl font-extrabold tabular-nums sm:text-3xl">{{ team?.roles.length ?? 0 }}</p>
						<p class="text-xs font-medium text-white/60">{{ team?.roles.length === 1 ? 'role' : 'roles' }}</p>
					</div>
					<button v-if="team?.can_manage" type="button" class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-primary-800 shadow-lg shadow-black/10 transition hover:-translate-y-0.5 hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white active:translate-y-0" @click="openAdd">
						<IconBase name="user-plus" class="h-4 w-4" /> Invite teammate
					</button>
				</div>
			</div>
		</header>

		<p v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

		<!-- Toolbar -->
		<div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
			<div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by role">
				<button type="button" class="tm-chip" :class="!roleFilter ? 'is-on' : ''" @click="roleFilter = ''">Everyone <span class="tm-chip-count">{{ members.length }}</span></button>
				<button v-for="role in roleChips" :key="role.name" type="button" class="tm-chip" :class="roleFilter === role.name ? 'is-on' : ''" :aria-pressed="roleFilter === role.name" @click="roleFilter = roleFilter === role.name ? '' : role.name">
					<span class="h-2 w-2 rounded-full" :style="{ backgroundColor: role.color }" />{{ role.name }} <span class="tm-chip-count">{{ role.count }}</span>
				</button>
			</div>
			<div class="flex flex-wrap items-center gap-2">
				<div class="relative">
					<IconBase name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
					<input v-model="search" type="search" placeholder="Search name or email" aria-label="Search team" class="tm-input !w-52 !py-2 !pl-9" />
				</div>
				<div class="tm-seg tm-seg-slide" role="tablist" aria-label="Filter by status">
					<span class="tm-seg-thumb" aria-hidden="true" :style="{ width: `calc((100% - 8px) / ${statusFilters.length})`, transform: `translateX(${statusIndex * 100}%)` }" />
					<button v-for="f in statusFilters" :key="f.value" type="button" role="tab" :aria-selected="statusFilter === f.value" :class="statusFilter === f.value ? 'is-on' : ''" @click="statusFilter = f.value">{{ f.label }}</button>
				</div>
				<div class="tm-seg" role="tablist" aria-label="View">
					<button type="button" role="tab" :aria-selected="view === 'badges'" :class="view === 'badges' ? 'is-on' : ''" aria-label="Badge view" @click="setView('badges')"><IconBase name="grid" class="h-4 w-4" /></button>
					<button type="button" role="tab" :aria-selected="view === 'list'" :class="view === 'list' ? 'is-on' : ''" aria-label="List view" @click="setView('list')"><IconBase name="menu" class="h-4 w-4" /></button>
				</div>
			</div>
		</div>

		<!-- Loading -->
		<div v-if="!team && !error" class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
			<div v-for="n in 4" :key="n" class="h-72 animate-pulse rounded-3xl bg-white ring-1 ring-gray-200" />
		</div>

		<div v-else-if="team" class="tm-stage">
			<Transition name="tm-xfade">
				<!-- Badge view -->
				<div v-if="view === 'badges'" :key="`badges-${filterKey}`" class="grid grid-cols-1 gap-x-5 gap-y-8 pt-3 sm:grid-cols-2 xl:grid-cols-4">
					<article v-for="(m, index) in filteredMembers" :key="m.id" class="tm-badge-wrap" :style="{ '--i': index }">
						<button type="button" class="tm-badge text-left" :class="m.status === 'deactivated' ? 'is-off' : ''" :aria-label="`Open ${m.name}`" @click="openMember(m)" @pointermove="spotlight">
							<span class="tm-clip" aria-hidden="true" />
							<div class="tm-badge-band" :style="{ backgroundColor: brandColor }" />
							<div class="relative flex flex-col items-center px-5 pb-4 pt-9 text-center">
								<span class="relative">
									<span class="tm-avatar h-20 w-20 overflow-hidden text-2xl ring-4 ring-white" :style="avatarStyle">{{ initialsOf(m.name) }}</span>
									<span class="absolute bottom-1 right-1 h-4 w-4 rounded-full ring-[3px] ring-white" :class="statusDot(m)" />
								</span>
								<p class="mt-3 max-w-full truncate text-base font-bold text-gray-950">{{ m.name }}</p>
								<p class="max-w-full truncate text-xs text-gray-500">{{ m.email }}</p>
								<span v-if="m.is_owner" class="tm-role-tag is-owner mt-3"><IconBase name="shield-check" class="h-3.5 w-3.5" /> Owner</span>
								<span v-else class="tm-role-tag mt-3" :style="{ '--c': roleColor(m.role) }">{{ m.role || 'No role yet' }}</span>
							</div>
							<div class="mt-auto flex items-center justify-between border-t border-dashed border-gray-200 px-5 py-3.5 text-xs">
								<span class="font-semibold text-gray-700">{{ m.is_owner ? 'Full access' : statusLabel(m) }}</span>
								<span v-if="m.user_id === myUserId" class="rounded-full bg-primary-50 px-2 py-0.5 font-bold text-primary-700">You</span>
								<span v-else-if="m.joined_at" class="text-gray-400">Since {{ formatDate(m.joined_at) }}</span>
							</div>
						</button>
					</article>

					<article v-if="team.can_manage && !isFiltered" class="tm-badge-wrap" :style="{ '--i': filteredMembers.length }">
						<button type="button" class="tm-seat group" @click="openAdd">
							<span class="flex h-14 w-14 items-center justify-center rounded-full border-2 border-dashed border-gray-300 text-gray-400 transition group-hover:scale-105 group-hover:border-primary-400 group-hover:text-primary-600"><IconBase name="plus" class="h-6 w-6" /></span>
							<span class="mt-3 text-sm font-semibold text-gray-700">Invite someone</span>
							<span class="mt-0.5 text-xs text-gray-400">A coordinator, door staff or crew</span>
						</button>
					</article>
				</div>

				<!-- List view -->
				<section v-else :key="`list-${filterKey}`" class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
					<div class="hidden grid-cols-[minmax(0,2.2fr)_minmax(0,1.3fr)_minmax(0,1.2fr)_40px] gap-4 border-b border-gray-100 bg-gray-50/70 px-6 py-2.5 text-xs font-semibold text-gray-500 md:grid">
						<span>Member</span><span>Role</span><span>Status</span><span />
					</div>
					<ul class="divide-y divide-gray-100">
						<li v-for="(m, index) in filteredMembers" :key="m.id" class="tm-row group grid cursor-pointer grid-cols-1 items-center gap-3 px-6 py-3.5 transition-colors hover:bg-gray-50 md:grid-cols-[minmax(0,2.2fr)_minmax(0,1.3fr)_minmax(0,1.2fr)_40px] md:gap-4" :style="{ '--i': index }" tabindex="0" @click="openMember(m)" @keydown.enter="openMember(m)">
							<div class="flex min-w-0 items-center gap-3" :class="m.status === 'deactivated' ? 'opacity-60' : ''">
								<span class="tm-avatar h-9 w-9 overflow-hidden text-xs" :style="avatarStyle">{{ initialsOf(m.name) }}</span>
								<div class="min-w-0">
									<p class="truncate text-sm font-semibold text-gray-950">{{ m.name }} <span v-if="m.user_id === myUserId" class="ml-1 rounded bg-primary-50 px-1.5 py-0.5 text-[11px] font-bold text-primary-700">You</span></p>
									<p class="truncate text-xs text-gray-500">{{ m.email }}</p>
								</div>
							</div>
							<span v-if="m.is_owner" class="tm-role-tag is-owner w-fit"><IconBase name="shield-check" class="h-3.5 w-3.5" /> Owner</span>
							<span v-else class="tm-role-tag w-fit" :style="{ '--c': roleColor(m.role) }">{{ m.role || 'No role yet' }}</span>
							<span class="text-sm font-semibold" :class="m.status === 'active' ? 'text-green-700' : m.status === 'invited' ? 'text-amber-700' : 'text-gray-400'">{{ statusLabel(m) }}</span>
							<IconBase name="arrow-right" class="h-4 w-4 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-primary-600 max-md:hidden" />
						</li>
					</ul>
				</section>
			</Transition>
		</div>

		<div v-if="team && members.length && !filteredMembers.length" class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
			<p class="font-semibold text-gray-900">No one matches</p>
			<p class="mt-1 text-sm text-gray-500">Try another name, or <button type="button" class="font-semibold text-primary-700 hover:underline" @click="clearFilters">show everyone</button>.</p>
		</div>

		<!-- Roles -->
		<section v-if="team" class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
			<div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-6 py-5">
				<div>
					<h2 class="text-base font-bold text-gray-950">Roles</h2>
					<p class="mt-0.5 text-sm text-gray-500">A role is a set of permissions across your whole organization. Owners always have every permission. For event-only access, add people to an event's team instead.</p>
				</div>
			</div>
			<ul class="divide-y divide-gray-100">
				<li v-for="r in team.roles" :key="r.id" class="flex flex-wrap items-start justify-between gap-3 px-6 py-3.5 text-sm">
					<div class="min-w-0">
						<p class="flex items-center gap-2 font-semibold text-gray-950"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: roleColor(r.name) }" />{{ r.name }} <span class="font-normal text-gray-400">· {{ r.members }} member{{ r.members === 1 ? '' : 's' }}</span></p>
						<p class="mt-0.5 text-xs leading-5 text-gray-500">{{ r.permissions.length ? r.permissions.map(permLabel).join(', ') : 'Only what they are given on assigned events' }}</p>
					</div>
					<button v-if="team.can_manage && !r.members" type="button" class="tm-inline-btn text-red-600" :disabled="busy" @click="act(() => api.removeRole(orgId, r.id), 'Role removed.', true)">Remove</button>
				</li>
				<li v-if="!team.roles.length" class="px-6 py-4 text-sm text-gray-400">No roles yet. Invite someone with one of the suggested roles, or create your own.</li>
			</ul>
			<form v-if="team.can_manage" class="border-t border-gray-100 bg-gray-50/60 px-6 py-5" @submit.prevent="createRole">
				<h3 class="text-sm font-bold text-gray-950">Create a role</h3>
				<input v-model="roleForm.name" type="text" maxlength="60" placeholder="Role name, e.g. Stage Crew" aria-label="Role name" class="tm-input mt-2 !w-full sm:!w-80" />
				<fieldset class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
					<legend class="sr-only">Permissions</legend>
					<label v-for="p in team.permissions" :key="p.key" class="flex min-h-10 cursor-pointer items-start gap-2.5 py-1.5 text-sm text-gray-700">
						<input v-model="roleForm.permissions" type="checkbox" :value="p.key" class="mt-0.5 accent-[#285F6b]">
						<span>{{ p.description }}</span>
					</label>
				</fieldset>
				<button type="submit" :disabled="busy || roleForm.name.trim().length < 2" class="tm-btn-primary mt-4 h-10 px-4 disabled:opacity-50">Create role</button>
			</form>
		</section>

		<!-- Side panel -->
		<Teleport to="body">
			<Transition name="tm-fade"><div v-if="panel" class="fixed inset-0 z-[110] bg-slate-950/40 backdrop-blur-[2px]" @click="closePanel" /></Transition>
			<Transition name="tm-slide">
				<aside v-if="panel" ref="panelEl" tabindex="-1" class="fixed inset-y-0 right-0 z-[115] flex w-full max-w-md flex-col bg-white shadow-2xl outline-none" role="dialog" aria-modal="true" :aria-label="panel === 'add' ? 'Invite teammate' : 'Teammate details'" @keydown.esc="closePanel">
					<!-- Invite -->
					<template v-if="panel === 'add' && team">
						<div class="flex items-start justify-between border-b border-gray-100 px-6 py-5">
							<div>
								<h2 class="text-lg font-bold text-gray-950">Invite a teammate</h2>
								<p class="mt-0.5 text-sm text-gray-500">NaSeRy e-mails them a link to join. New to NaSeRy? The link creates their account, so they only choose a password.</p>
							</div>
							<button type="button" class="tap-target rounded-lg p-1.5 text-gray-400 hover:bg-gray-100" aria-label="Close" @click="closePanel"><IconBase name="x" class="h-5 w-5" /></button>
						</div>
						<form class="flex min-h-0 flex-1 flex-col" novalidate @submit.prevent="invite">
							<div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5">
								<div class="grid grid-cols-2 gap-3">
									<div data-field="firstName">
										<label for="tm-first" class="tm-label">First name</label>
										<input id="tm-first" ref="firstField" v-model="inviteForm.firstName" type="text" class="tm-field" :class="inviteCheck.err('firstName') ? 'tm-invalid' : ''" />
										<p v-if="inviteCheck.err('firstName')" class="tm-error">{{ inviteCheck.err('firstName') }}</p>
									</div>
									<div data-field="lastName">
										<label for="tm-last" class="tm-label">Last name</label>
										<input id="tm-last" v-model="inviteForm.lastName" type="text" class="tm-field" :class="inviteCheck.err('lastName') ? 'tm-invalid' : ''" />
										<p v-if="inviteCheck.err('lastName')" class="tm-error">{{ inviteCheck.err('lastName') }}</p>
									</div>
								</div>
								<div data-field="email">
									<label for="tm-email" class="tm-label">E-mail</label>
									<input id="tm-email" v-model="inviteForm.email" type="email" placeholder="teammate@example.com" class="tm-field" :class="inviteCheck.err('email') ? 'tm-invalid' : ''" />
									<p v-if="inviteCheck.err('email')" class="tm-error">{{ inviteCheck.err('email') }}</p>
								</div>
								<div>
									<span class="tm-label">Role</span>
									<RoleSelect v-model="inviteForm.role" :team="team" />
									<p v-if="selectedRoleSummary" class="tm-hint">{{ selectedRoleSummary }}</p>
								</div>
								<p v-if="error" class="rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-700">{{ error }}</p>
							</div>
							<div class="grid grid-cols-[auto_1fr] gap-2 border-t border-gray-100 px-6 py-4">
								<button type="button" class="tm-btn-secondary h-11 justify-center px-5" @click="closePanel">Cancel</button>
								<button type="submit" class="tm-btn-primary h-11 justify-center" :disabled="busy">
									<IconBase :name="busy ? 'refresh-cw' : 'send'" class="h-4 w-4" :class="busy ? 'animate-spin' : ''" />
									{{ busy ? 'Sending…' : 'Send invite' }}
								</button>
							</div>
						</form>
					</template>

					<!-- Teammate details -->
					<template v-else-if="selected && team">
						<button type="button" class="absolute right-4 top-4 z-20 flex h-9 w-9 items-center justify-center rounded-full bg-white/90 text-gray-600 shadow-sm ring-1 ring-black/5 backdrop-blur transition hover:bg-white hover:text-gray-900" aria-label="Close" @click="closePanel"><IconBase name="x" class="h-5 w-5" /></button>
						<div class="min-h-0 flex-1 overflow-y-auto">
							<div class="relative h-28 overflow-hidden" :style="{ backgroundColor: brandColor }"><div class="tm-hero-dots" aria-hidden="true" /></div>
							<div class="px-6 pb-5">
								<div class="relative z-10 -mt-11 flex items-end justify-between gap-3">
									<span class="tm-avatar h-[5.5rem] w-[5.5rem] overflow-hidden text-2xl shadow-sm ring-4 ring-white" :class="selected.status === 'deactivated' ? 'grayscale opacity-60' : ''" :style="avatarStyle">{{ initialsOf(selected.name) }}</span>
									<span class="mb-1 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="selected.status === 'active' ? 'bg-green-50 text-green-700' : selected.status === 'invited' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500'"><span class="h-1.5 w-1.5 rounded-full bg-current" />{{ statusLabel(selected) }}</span>
								</div>
								<h2 class="mt-3 truncate text-xl font-bold text-gray-950">{{ selected.name }}</h2>
								<p class="mt-0.5 text-sm text-gray-500">{{ selected.is_owner ? 'Owner' : selected.role || 'No role yet' }}<template v-if="selected.joined_at"> · Joined {{ formatDate(selected.joined_at) }}</template><template v-else-if="selected.invited_at"> · Invited {{ formatDate(selected.invited_at) }}</template></p>
							</div>

							<div class="divide-y divide-gray-100 border-t border-gray-100">
								<!-- Role -->
								<div v-if="canEdit(selected) && selected.status !== 'deactivated'" class="tm-group">
									<p class="tm-group-title">Role</p>
									<FormsSelect :model-value="selected.role_id" :options="team.roles.map(r => ({ value: r.id, label: r.name }))" placeholder="Choose a role" :can-clear="false" :disabled="busy" @update:model-value="changeRole(selected, String($event))" />
									<p class="tm-hint">{{ rolePerms(selected.role_id) }} Changes save right away.</p>
								</div>

								<!-- Invitation -->
								<div v-if="team.can_manage && selected.status === 'invited'" class="tm-group">
									<p class="tm-group-title">Invitation</p>
									<p class="text-sm text-gray-700">{{ selected.account_pending ? pendingHint : 'They accept after signing in to their business account.' }}</p>
									<button type="button" class="tm-btn-secondary mt-3 h-10 px-4" :disabled="busy" @click="act(() => api.resend(orgId, selected!.id), `Invitation sent again to ${selected!.email}.`, true)"><IconBase name="send" class="h-4 w-4" /> Resend invitation</button>
								</div>

								<!-- Contact -->
								<div class="tm-group">
									<p class="tm-group-title">Contact</p>
									<div class="space-y-2">
										<div class="flex h-11 items-center justify-between gap-3 rounded-xl border border-gray-200 pl-3.5 pr-1.5">
											<span class="flex min-w-0 items-center gap-2.5 text-sm text-gray-800"><IconBase name="send" class="h-4 w-4 shrink-0 text-gray-400" /><span class="truncate">{{ selected.email || 'No email' }}</span></span>
											<a v-if="selected.email" :href="`mailto:${selected.email}`" class="tm-inline-btn text-primary-700">Email</a>
										</div>
										<div class="flex h-11 items-center justify-between gap-3 rounded-xl border border-gray-200 pl-3.5 pr-1.5">
											<span class="flex min-w-0 items-center gap-2.5 text-sm" :class="selected.phone ? 'text-gray-800' : 'text-gray-400'"><IconBase name="phone" class="h-4 w-4 shrink-0 text-gray-400" /><span class="truncate">{{ selected.phone || 'No phone number' }}</span></span>
											<a v-if="selected.phone" :href="`tel:${selected.phone}`" class="tm-inline-btn text-primary-700">Call</a>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div class="flex flex-wrap gap-2 border-t border-gray-100 px-6 py-4">
							<template v-if="canEdit(selected)">
								<button v-if="selected.status === 'deactivated'" type="button" class="tm-btn-primary h-11 flex-1 justify-center" :disabled="busy" @click="act(() => api.reactivate(orgId, selected!.id), 'Reactivated.', true)">Reactivate</button>
								<button v-else type="button" class="tm-btn-secondary h-11 flex-1 justify-center !text-red-600 hover:!bg-red-50" :disabled="busy" @click="remove(selected)">{{ selected.status === 'invited' ? 'Cancel invitation' : 'Remove from team' }}</button>
							</template>
							<NuxtLink v-if="selected.status === 'active'" to="/organizer/eventmanagement/all-events" class="tm-btn-primary h-11 flex-1 justify-center" @click="closePanel"><IconBase name="clipboard-list" class="h-4 w-4" /> Assign tasks</NuxtLink>
						</div>
					</template>
				</aside>
			</Transition>
		</Teleport>

		<!-- Remove a member: their open tasks go to someone else, or stay unassigned. -->
		<Teleport to="body">
			<div v-if="removing" class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="remove-title" @click.self="removing = null">
				<div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
					<h2 id="remove-title" class="text-lg font-bold text-gray-900">Remove {{ removing.member.name }}?</h2>
					<p class="mt-1 text-sm text-gray-500">They lose access to {{ organization?.display_name }} right away and are taken off every event team. Their account stays theirs, and their finished work keeps their name. You can reactivate them later.</p>
					<div v-if="removing.loading" class="py-6 text-center text-sm text-gray-500">Checking their tasks…</div>
					<template v-else>
						<div v-if="removing.tasks.length" class="mt-4">
							<p class="text-sm font-semibold text-gray-800">{{ removing.tasks.length }} open {{ removing.tasks.length === 1 ? 'task' : 'tasks' }}</p>
							<ul class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-xl bg-gray-50 px-3 py-2 text-xs text-gray-600">
								<li v-for="t in removing.tasks" :key="t.id"><span class="font-semibold text-gray-800">{{ t.name }}</span> · {{ t.event.name }}<template v-if="t.due_at"> · due {{ new Date(t.due_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) }}</template></li>
							</ul>
							<label class="mt-3 block text-sm font-semibold text-gray-800" for="reassign">Hand them over to</label>
							<select id="reassign" v-model="removing.reassignTo" class="tm-field mt-1">
								<option value="">Nobody yet (mark them "Needs a new assignee")</option>
								<option v-for="o in reassignOptions" :key="o.id" :value="o.id">{{ o.name }} ({{ o.role }})</option>
							</select>
						</div>
						<p v-else class="mt-4 rounded-xl bg-gray-50 px-3 py-2 text-sm text-gray-600">They have no open tasks.</p>
						<div class="mt-6 flex justify-end gap-2">
							<button type="button" class="tm-btn-secondary h-10 px-4" @click="removing = null">Keep them</button>
							<button type="button" class="h-10 rounded-xl bg-red-600 px-4 text-sm font-bold text-white disabled:opacity-60" :disabled="busy" @click="confirmRemove">Remove from team</button>
						</div>
					</template>
				</div>
			</div>
		</Teleport>

		<Teleport to="body">
			<Transition name="tm-toast">
				<div v-if="notice" class="fixed bottom-6 left-1/2 z-[130] flex -translate-x-1/2 items-center gap-2.5 rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-xl" role="status">
					<IconBase name="check-circle" class="h-4 w-4 text-green-400" />
					{{ notice }}
				</div>
			</Transition>
		</Teleport>
	</div>
</template>

<script setup lang="ts">
// The team (B8) in the redesign's crew view: invitations by e-mail (they choose
// their own password), roles with permissions, removal with task handover.
import type { OpenTask, RoleChoice, Team, TeamMember } from '~/composables/useTeam'

definePageMeta({ layout: 'dashboard' })

const { organization, fetchOrganization } = useOrganization()
const { user } = useAuth()
const api = useTeam()

const team = ref<Team | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const inviteForm = reactive({ firstName: '', lastName: '', email: '', role: 'preset:coordinator' })
const inviteCheck = useFormCheck(() => ({
	firstName: inviteForm.firstName.trim() ? '' : 'Add their first name.',
	lastName: inviteForm.lastName.trim() ? '' : 'Add their last name.',
	email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(inviteForm.email.trim()) ? '' : 'Enter their e-mail address.',
}))
const pendingHint = "They haven't opened the link in their e-mail yet (it works for 72 hours). Resend it if they lost it."
const removing = ref<{ member: TeamMember, tasks: OpenTask[], loading: boolean, reassignTo: string } | null>(null)
const reassignOptions = computed(() => members.value.filter(m => m.status === 'active' && m.id !== removing.value?.member.id))
const roleForm = reactive({ name: '', permissions: [] as string[] })
const orgId = computed(() => organization.value?.id ?? '')
const myUserId = computed(() => user.value?.id)
const brandColor = computed(() => organization.value?.profile?.banner_color || '#285F6b')
const avatarStyle = computed(() => ({ color: '#ffffff', backgroundColor: brandColor.value }))

const members = computed(() => team.value?.members ?? [])
const activeMembers = computed(() => members.value.filter(m => m.status === 'active'))
const invitedMembers = computed(() => members.value.filter(m => m.status === 'invited'))

// ---------- Display helpers ----------
const roleColors = ['#285F6b', '#7c3aed', '#be123c', '#b45309', '#15803d', '#1d4ed8', '#0e7490', '#a21caf']
function hashOf(value: string) { let h = 0; for (const ch of value) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return h }
function roleColor(name: string | null) { return name ? roleColors[hashOf(name) % roleColors.length]! : '#9ca3af' }
function initialsOf(name: string) {
	const w = name.split(/\s+/).filter(Boolean)
	return ((w[0]?.[0] ?? '') + (w.length > 1 ? w[w.length - 1]?.[0] ?? '' : '')).toUpperCase() || '?'
}
function formatDate(value: string) { return new Date(value).toLocaleDateString('en-PH', { day: 'numeric', month: 'short', year: 'numeric' }) }
function statusLabel(m: TeamMember) { return m.status === 'invited' ? (m.account_pending ? 'Waiting for set-up' : 'Invited') : m.status === 'active' ? 'Active' : 'Removed' }
function statusDot(m: TeamMember) { return m.status === 'active' ? 'bg-green-500' : m.status === 'invited' ? 'bg-amber-400' : 'bg-gray-300' }
function permLabel(key: string) { return team.value?.permissions.find(p => p.key === key)?.description ?? key }
function rolePerms(roleId: string | null) {
	const perms = team.value?.roles.find(r => r.id === roleId)?.permissions
	return perms ? (perms.length ? `Can: ${perms.map(permLabel).join(', ')}.` : 'Works only on the events you add them to.') : ''
}
function canEdit(m: TeamMember) { return Boolean(team.value?.can_manage && !m.is_owner && m.user_id !== myUserId.value) }

// Soft light that follows the pointer across a badge.
function spotlight(event: PointerEvent) {
	const el = event.currentTarget as HTMLElement
	const rect = el.getBoundingClientRect()
	el.style.setProperty('--mx', `${event.clientX - rect.left}px`)
	el.style.setProperty('--my', `${event.clientY - rect.top}px`)
}

// ---------- Filters & view ----------
const roleChips = computed(() => {
	const counts = new Map<string, number>()
	for (const m of members.value) {
		const name = m.is_owner ? 'Owner' : m.role || 'No role'
		counts.set(name, (counts.get(name) ?? 0) + 1)
	}
	return [...counts.entries()].map(([name, count]) => ({ name, count, color: name === 'Owner' ? '#285F6b' : roleColor(name === 'No role' ? null : name) }))
})
const search = ref('')
const statusFilters = [{ value: 'all' as const, label: 'All' }, { value: 'active' as const, label: 'Active' }, { value: 'invited' as const, label: 'Invited' }, { value: 'deactivated' as const, label: 'Removed' }]
const statusFilter = ref<'all' | 'active' | 'invited' | 'deactivated'>('all')
const roleFilter = ref('')
const statusIndex = computed(() => Math.max(0, statusFilters.findIndex(f => f.value === statusFilter.value)))
const filteredMembers = computed(() => {
	const q = search.value.trim().toLowerCase()
	return members.value.filter((m) => {
		if (statusFilter.value !== 'all' && m.status !== statusFilter.value) return false
		const roleName = m.is_owner ? 'Owner' : m.role || 'No role'
		if (roleFilter.value && roleName !== roleFilter.value) return false
		return !q || m.name.toLowerCase().includes(q) || (m.email ?? '').toLowerCase().includes(q)
	})
})
const isFiltered = computed(() => !!search.value.trim() || statusFilter.value !== 'all' || !!roleFilter.value)
const filterKey = computed(() => `${statusFilter.value}|${roleFilter.value}`)
function clearFilters() { search.value = ''; statusFilter.value = 'all'; roleFilter.value = '' }

const view = ref<'badges' | 'list'>('badges')
function setView(v: 'badges' | 'list') {
	view.value = v
	try { localStorage.setItem('nasery.team.view.v2', v) } catch { /* preference not stored */ }
}

// ---------- Side panel ----------
const panel = ref<'' | 'add' | 'member'>('')
const selectedId = ref<string | null>(null)
const selected = computed(() => members.value.find(m => m.id === selectedId.value) ?? null)
const panelEl = ref<HTMLElement | null>(null)
const firstField = ref<HTMLInputElement | null>(null)
function openAdd() {
	error.value = ''
	panel.value = 'add'
	nextTick(() => firstField.value?.focus())
}
function openMember(m: TeamMember) {
	error.value = ''
	selectedId.value = m.id
	panel.value = 'member'
	nextTick(() => panelEl.value?.focus())
}
function closePanel() { if (!busy.value) panel.value = '' }

// ---------- Actions ----------
function roleChoice(value: string): RoleChoice {
	return value.startsWith('preset:') ? { preset: value.slice(7) } : { role_id: value }
}
const selectedRoleSummary = computed(() => {
	if (!team.value) return ''
	const v = inviteForm.role
	const perms = v.startsWith('preset:')
		? team.value.presets.find(p => p.key === v.slice(7))?.permissions
		: team.value.roles.find(r => r.id === v)?.permissions
	return perms ? (perms.length ? `Can: ${perms.map(permLabel).join(', ')}.` : 'Works only on the events you add them to.') : ''
})
let noticeTimer: ReturnType<typeof setTimeout> | null = null
async function act<T>(fn: () => Promise<T>, success: string, reload = false) {
	error.value = ''
	busy.value = true
	try {
		await fn()
		if (success) {
			notice.value = success
			if (noticeTimer) clearTimeout(noticeTimer)
			noticeTimer = setTimeout(() => { notice.value = '' }, 3200)
		}
		if (reload) team.value = await api.team(orgId.value)
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}
async function invite() {
	if (!inviteCheck.ok()) return
	const email = inviteForm.email.trim()
	await act(() => api.invite(orgId.value, email, roleChoice(inviteForm.role), { first_name: inviteForm.firstName.trim(), last_name: inviteForm.lastName.trim() }),
		`Invitation sent to ${email}.`, true)
	if (!error.value) {
		Object.assign(inviteForm, { firstName: '', lastName: '', email: '' })
		inviteCheck.reset()
		panel.value = ''
	}
}
function changeRole(m: TeamMember, roleId: string) {
	act(() => api.changeRole(orgId.value, m.id, { role_id: roleId }), `${m.name}'s role changed.`, true)
}
async function remove(m: TeamMember) {
	if (m.status === 'invited') {
		if (await confirmDialog(`Cancel the invitation for ${m.name}?`, { confirmLabel: 'Cancel invitation' })) {
			await act(() => api.remove(orgId.value, m.id), 'Invitation cancelled.', true)
			panel.value = ''
		}
		return
	}
	removing.value = { member: m, tasks: [], loading: true, reassignTo: '' }
	try {
		removing.value.tasks = await api.openTasks(orgId.value, m.id)
		removing.value.loading = false
	} catch (e) {
		error.value = authMessage(e)
		removing.value = null
	}
}
async function confirmRemove() {
	const r = removing.value
	if (!r) return
	let message = `${r.member.name} was removed.`
	await act(async () => { message = (await api.remove(orgId.value, r.member.id, r.reassignTo || null)).message ?? message }, '', true)
	if (!error.value) notice.value = message
	removing.value = null
	panel.value = ''
}
async function createRole() {
	await act(() => api.createRole(orgId.value, { name: roleForm.name.trim(), permissions: roleForm.permissions }), 'Role created.', true)
	if (!error.value) Object.assign(roleForm, { name: '', permissions: [] })
}

onMounted(async () => {
	try { const saved = localStorage.getItem('nasery.team.view.v2'); if (saved === 'list' || saved === 'badges') view.value = saved } catch { /* default view */ }
	try {
		const org = organization.value ?? await fetchOrganization()
		if (org) team.value = await api.team(org.id)
	} catch (e) {
		error.value = authMessage(e)
	}
})
</script>

<style scoped>
.tm-label { @apply mb-1.5 block text-sm font-semibold text-gray-800; }
.tm-error { @apply mt-1.5 text-xs font-medium text-red-600; }
.tm-input { @apply w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-300 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10 disabled:bg-gray-50; }
.tm-invalid { @apply border-red-300 focus:border-red-400 focus:ring-red-500/10; }
.tm-field.tm-invalid { @apply border-red-300; }
.tm-btn-primary { @apply inline-flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-primary-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50; }
.tm-btn-secondary { @apply inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600 disabled:cursor-not-allowed disabled:opacity-50; }
.tm-group { @apply space-y-4 px-6 py-5; }
.tm-group-title { @apply text-xs font-bold text-primary-800; }
.tm-optional { @apply ml-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold text-gray-500; }
.tm-hint { @apply mt-1.5 text-xs text-gray-500; }
.tm-field { @apply h-11 w-full rounded-xl border border-gray-200 bg-white px-3.5 text-sm text-gray-900 outline-none transition placeholder:font-sans placeholder:tracking-normal placeholder:text-gray-400 hover:border-gray-300 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10; }
.tm-field-icon { @apply pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400; }
.tm-option { @apply flex h-11 items-center gap-2.5 rounded-xl border border-gray-200 bg-white px-3.5 text-left text-sm font-medium text-gray-700 transition hover:border-gray-300 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600; }
.tm-option.is-on { @apply border-primary-600 bg-primary-25 text-primary-800 ring-1 ring-primary-600; }
.tm-inline-btn { @apply inline-flex h-8 items-center gap-1 rounded-lg px-2.5 text-xs font-semibold text-gray-500 transition hover:bg-gray-100 hover:text-gray-900; }
.tm-icon-btn { @apply flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700; }
.tm-avatar { @apply inline-flex shrink-0 select-none items-center justify-center rounded-full font-bold; }

.tm-chip { @apply inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-600 transition hover:border-gray-300 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600; }
.tm-chip.is-on { @apply border-primary-200 bg-primary-50 text-primary-800 shadow-sm shadow-primary-900/5; }
.tm-chip-count { @apply text-xs tabular-nums opacity-60; }
.tm-seg { @apply flex rounded-xl bg-gray-100 p-1; }
.tm-seg > button { @apply flex min-h-9 items-center rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-500 transition hover:text-gray-800; }
.tm-seg > button.is-on { @apply bg-white text-gray-950 shadow-sm; }
.tm-seg-slide { position: relative; display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); }
.tm-seg-slide > button { position: relative; z-index: 1; justify-content: center; min-width: 3.5rem; }
.tm-seg-slide > button.is-on { background: transparent; box-shadow: none; }
.tm-seg-thumb {
	position: absolute; top: 0.25rem; bottom: 0.25rem; left: 0.25rem; width: calc((100% - 0.5rem) / 3);
	border-radius: 0.5rem; background: white; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08), 0 1px 3px rgba(16, 24, 40, 0.06);
	transition: transform 0.32s cubic-bezier(0.3, 1.3, 0.5, 1);
}

/* Role tag, tinted from its role colour. */
.tm-role-tag {
	display: inline-flex; align-items: center; gap: 0.4rem; max-width: 100%;
	border-radius: 9999px; border: 1px solid #e5e7eb; padding: 0.2rem 0.65rem; font-size: 0.75rem; font-weight: 600;
	color: #374151; background: white; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.tm-role-tag::before { content: ''; width: 6px; height: 6px; border-radius: 9999px; background: var(--c, #9ca3af); flex-shrink: 0; }
.tm-role-tag.is-owner { color: #1c4f5c; border-color: #d7e6e8; background: #f6fafb; }
.tm-role-tag.is-owner::before { display: none; }

/* Header dot texture */
.tm-hero-dots {
	position: absolute; inset: 0; pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.16) 1px, transparent 1.3px); background-size: 16px 16px;
	mask-image: linear-gradient(105deg, transparent 15%, black 70%);
}

.tm-dots {
	position: absolute; inset: 0; pointer-events: none;
	background-image: radial-gradient(rgba(16, 63, 77, 0.09) 1px, transparent 1.3px); background-size: 16px 16px;
	mask-image: linear-gradient(105deg, transparent 35%, black 85%);
}

/* Staff badges, hanging from a lanyard clip */
.tm-badge-wrap { position: relative; animation: tm-rise 0.5s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 55ms); }
.tm-badge {
	position: relative; display: flex; flex-direction: column; width: 100%; min-height: 17.5rem;
	border-radius: 1.5rem; background: white; border: 1px solid #e5e7eb;
	box-shadow: 0 1px 2px rgba(16, 63, 77, 0.05);
	transform-origin: 50% -14px;
	transition: transform 0.35s cubic-bezier(0.3, 1.4, 0.5, 1), box-shadow 0.25s ease;
}
button.tm-badge:hover { transform: rotate(-1deg) translateY(-3px); border-color: #d7e6e8; box-shadow: 0 20px 40px -24px rgba(16, 63, 77, 0.4); }
button.tm-badge:focus-visible { outline: none; box-shadow: 0 0 0 3px white, 0 0 0 5px #346f7a; }
.tm-badge.is-off { background: #fafafa; }
.tm-badge.is-off .tm-avatar, .tm-badge.is-off .tm-badge-band { filter: grayscale(1); opacity: 0.55; }
.tm-badge-band { position: absolute; inset: 0 0 auto 0; height: 4.25rem; border-radius: 1.5rem 1.5rem 0 0; background: #285F6b; }
.tm-badge-band::after {
	content: ''; position: absolute; inset: 0; border-radius: inherit;
	background-image: radial-gradient(rgba(255, 255, 255, 0.2) 1px, transparent 1.3px); background-size: 12px 12px;
}
.tm-badge-band { overflow: hidden; }
.tm-badge-band.has-cover { background: #e5e7eb; }
.tm-badge-band.has-cover::after { display: none; }
.tm-cover { width: 100%; height: 100%; object-fit: cover; }
/* Pointer spotlight */
.tm-badge::before {
	content: ''; position: absolute; inset: 0; z-index: 1; border-radius: inherit; pointer-events: none; opacity: 0; transition: opacity 0.3s ease;
	background: radial-gradient(220px circle at var(--mx, 50%) var(--my, 30%), rgba(65, 127, 136, 0.09), transparent 65%);
}
.tm-badge:hover::before { opacity: 1; }
.tm-badge > * { position: relative; z-index: 2; }
.tm-badge > .tm-badge-band { position: absolute; z-index: 0; }
.tm-badge > .tm-clip { position: absolute; z-index: 3; }
.tm-badge .tm-avatar { transition: box-shadow 0.3s ease, transform 0.3s ease; }
button.tm-badge:hover .tm-avatar { box-shadow: 0 0 0 4px white, 0 0 0 6px #a9c9cd; transform: scale(1.03); }
.tm-clip {
	position: absolute; top: -12px; left: 50%; z-index: 2; width: 3.25rem; height: 1.4rem; transform: translateX(-50%);
	border-radius: 0.5rem; background: linear-gradient(#e5e7eb, #d1d5db); box-shadow: inset 0 -2px 0 rgba(0, 0, 0, 0.06);
}
.tm-clip::after {
	content: ''; position: absolute; left: 50%; top: 50%; width: 1.6rem; height: 0.35rem;
	transform: translate(-50%, -50%); border-radius: 9999px; background: #9ca3af;
}
.tm-seat {
	@apply flex w-full flex-col items-center justify-center rounded-3xl border-2 border-dashed border-gray-200 bg-white/50 px-5 text-center transition hover:border-primary-300 hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600;
	min-height: 17.5rem;
}

/* Access switch */
.tm-switch { @apply relative inline-flex h-6 w-11 shrink-0 items-center rounded-full bg-gray-200 transition-colors duration-200 group-hover:bg-gray-300 group-focus-visible:outline group-focus-visible:outline-2 group-focus-visible:outline-offset-2 group-focus-visible:outline-primary-600; }
.tm-switch.is-on { @apply bg-primary-700 group-hover:bg-primary-800; }
.tm-knob { @apply absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200; }
.tm-switch.is-on .tm-knob { transform: translateX(20px); }

/* Motion */
.tm-row { animation: tm-rise 0.4s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 40ms); }
@keyframes tm-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
.tm-new .tm-badge, li.tm-new { animation: tm-flash 2.6s ease-out both; }
@keyframes tm-flash {
	0%, 30% { box-shadow: 0 0 0 4px rgba(65, 127, 136, 0.35); background-color: #f6fafb; }
	100% { box-shadow: 0 1px 2px rgba(16, 63, 77, 0.05); }
}
.tm-pop { animation: tm-pop 0.55s cubic-bezier(0.3, 1.6, 0.5, 1) both; }
@keyframes tm-pop { from { transform: scale(0.5); opacity: 0; } to { transform: none; opacity: 1; } }
.tm-pass { animation: tm-rise 0.45s ease-out both; }

/* Stage keeps the outgoing set inside its own box (room left for clips, sway and shadows). */
.tm-stage { position: relative; overflow: hidden; padding: 12px 16px 40px; margin: -12px -16px -40px; }
.tm-xfade-leave-active { position: absolute; top: 12px; left: 16px; right: 16px; transition: opacity 0.18s ease, transform 0.18s ease; pointer-events: none; }
.tm-xfade-leave-to { opacity: 0; transform: translateY(-6px); }
.tm-fade-enter-active, .tm-fade-leave-active { transition: opacity 0.2s ease; }
.tm-fade-enter-from, .tm-fade-leave-to { opacity: 0; }
.tm-slide-enter-active, .tm-slide-leave-active { transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.2, 1); }
.tm-slide-enter-from, .tm-slide-leave-to { transform: translateX(100%); }
.tm-swap-enter-active, .tm-swap-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.tm-swap-enter-from { opacity: 0; transform: translateY(8px); }
.tm-swap-leave-to { opacity: 0; transform: translateY(-6px); }
.tm-toast-enter-active, .tm-toast-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.tm-toast-enter-from, .tm-toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }

@media (prefers-reduced-motion: reduce) {
	.tm-badge-wrap, .tm-row, .tm-new .tm-badge, li.tm-new, .tm-pop, .tm-pass { animation: none; }
	button.tm-badge:hover, button.tm-badge:hover .tm-avatar { transform: none; }
	.tm-badge::before { display: none; }
	.tm-seg-thumb, .tm-xfade-leave-active, .tm-badge, .tm-slide-enter-active, .tm-slide-leave-active, .tm-swap-enter-active, .tm-swap-leave-active { transition: none; }
}
</style>
