// Unit tests for the frontend's plain logic (price math, form helpers).
// Run: npm test
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	resolve: { alias: { '~': fileURLToPath(new URL('./app', import.meta.url)) } },
	test: {
		include: ['tests/**/*.test.ts'],
		setupFiles: ['tests/setup.ts'],
	},
})
