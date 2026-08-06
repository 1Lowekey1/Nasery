# GitHub Copilot Repository Instructions

## Tech Stack Declarations
- Frontend: Nuxt 3 (Composition API, `<script setup>`, TypeScript/JavaScript)
- Backend: Laravel (Strictly typed API controllers, JsonResponse)
- Database: PostgreSQL via Supabase

## Copilot Behavioral Directives
- Follow Nuxt 3 conventions: rely on auto-imports for Vue reactivity and Nuxt composables.
- Prefer `$fetch` over `axios` for Nuxt frontend code snippets.
- Always generate thin Laravel controllers accompanied by a dedicated FormRequest validation class.
- Always include explicit data types for function arguments and return types.
- Never hardcode API keys or secret tokens; always use `useRuntimeConfig()` in Nuxt or `env()` in Laravel.