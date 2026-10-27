// Nuxt auto-imports these in the app; plain Vitest needs them on the global scope.
import { formatPeso } from '~/composables/useMarketplace'

Object.assign(globalThis, { formatPeso })
