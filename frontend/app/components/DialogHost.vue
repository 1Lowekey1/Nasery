<template>
  <Teleport to="body">
    <Transition name="dialog-fade">
      <div v-if="dialog" class="fixed inset-0 z-[400] flex items-center justify-center bg-gray-900/40 p-4" @click.self="cancel">
        <form class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" role="alertdialog" aria-modal="true"
          :aria-labelledby="titleId" @submit.prevent="accept" @keydown.esc="cancel">
          <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
              :class="dialog.danger ? 'bg-red-50 text-red-600' : 'bg-primary-50 text-primary-700'">
              <IconBase :name="dialog.kind === 'prompt' ? 'message' : dialog.danger ? 'x-circle' : 'check-circle'" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
              <h2 :id="titleId" class="text-base font-bold text-gray-900">{{ dialog.title }}</h2>
              <p class="mt-1 whitespace-pre-line text-sm leading-6 text-gray-600">{{ dialog.description }}</p>
            </div>
          </div>
          <textarea v-if="dialog.kind === 'prompt'" ref="field" v-model="text" rows="3" maxlength="1000"
            :placeholder="dialog.placeholder"
            class="mt-4 w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15" />
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50" @click="cancel">
              {{ dialog.cancelLabel }}
            </button>
            <button ref="confirmButton" type="submit"
              class="rounded-xl px-4 py-2 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
              :class="dialog.danger ? 'bg-red-600 hover:bg-red-700' : 'bg-primary-700 hover:bg-primary-900'"
              :disabled="dialog.kind === 'prompt' && text.trim().length < dialog.minLength">
              {{ dialog.confirmLabel }}
            </button>
          </div>
        </form>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
const dialog = useDialogState()
const text = ref('')
const field = ref<HTMLTextAreaElement | null>(null)
const confirmButton = ref<HTMLButtonElement | null>(null)
const titleId = useId()

watch(dialog, async (value) => {
  text.value = ''
  if (!value) return
  await nextTick()
  ;(value.kind === 'prompt' ? field.value : confirmButton.value)?.focus()
})

function accept() {
  const current = dialog.value
  if (!current) return
  if (current.kind === 'prompt') {
    if (text.value.trim().length < current.minLength) return
    current.resolve(text.value.trim())
  } else {
    current.resolve(true)
  }
}

function cancel() {
  dialog.value?.resolve(dialog.value.kind === 'confirm' ? false : null)
}
</script>

<style scoped>
.dialog-fade-enter-active, .dialog-fade-leave-active { transition: opacity 150ms ease; }
.dialog-fade-enter-from, .dialog-fade-leave-to { opacity: 0; }
</style>
