// In-page replacements for window.confirm / window.prompt (native pop-ups look
// out of place and are blocked in some embedded browsers). One <DialogHost />
// in app.vue shows whichever dialog is waiting.
//
//   if (!(await confirmDialog('Remove this item?'))) return
//   const reason = await promptDialog('Why?', { minLength: 3 })   // null = cancelled

export interface DialogRequest {
  kind: 'confirm' | 'prompt'
  title: string
  description: string
  confirmLabel: string
  cancelLabel: string
  danger: boolean
  placeholder: string
  minLength: number
  resolve: (value: string | boolean | null) => void
}

interface DialogOptions {
  title?: string
  confirmLabel?: string
  cancelLabel?: string
  danger?: boolean
}

export const useDialogState = () => useState<DialogRequest | null>('dialog.current', () => null)

function open(request: Omit<DialogRequest, 'resolve'>): Promise<string | boolean | null> {
  const current = useDialogState()
  // A dialog already waiting is cancelled rather than stacked.
  current.value?.resolve(request.kind === 'confirm' ? false : null)
  return new Promise(resolve => {
    current.value = {
      ...request,
      resolve: (value) => {
        current.value = null
        resolve(value)
      },
    }
  })
}

export async function confirmDialog(description: string, options: DialogOptions = {}): Promise<boolean> {
  return (await open({
    kind: 'confirm',
    title: options.title ?? 'Are you sure?',
    description,
    confirmLabel: options.confirmLabel ?? 'Yes, continue',
    cancelLabel: options.cancelLabel ?? 'Cancel',
    danger: options.danger ?? true,
    placeholder: '',
    minLength: 0,
  })) === true
}

export async function promptDialog(description: string, options: DialogOptions & { placeholder?: string, minLength?: number } = {}): Promise<string | null> {
  const value = await open({
    kind: 'prompt',
    title: options.title ?? 'A little more detail',
    description,
    confirmLabel: options.confirmLabel ?? 'Send',
    cancelLabel: options.cancelLabel ?? 'Cancel',
    danger: options.danger ?? false,
    placeholder: options.placeholder ?? '',
    minLength: options.minLength ?? 1,
  })
  return typeof value === 'string' ? value : null
}
