# Project AI Coding Standards & Constraints

## 1. Core Tech Stack
- Frontend: Nuxt 3 (SPA mode `ssr: false`, Composition API `<script setup>`, $fetch, Pinia)
- Backend: Laravel (API-only mode, Laravel Sanctum SPA authentication)
- Database: Supabase (PostgreSQL with Row Level Security enabled via migrations)

## 2. Nuxt 3 Conventions
- Leverage Nuxt auto-imports: do not write explicit `import { ref, computed } from 'vue'` or `import { useRoute } from 'vue-router'`.
- Use Nuxt directory structure: `pages/` for views, `components/` for UI, `composables/` for reusable logic/API calls.
- Use `$fetch` inside `composables/useApi.ts` for network requests instead of importing Axios.
- Keep SPA mode enabled (`ssr: false` in `nuxt.config.ts`) to ensure seamless Sanctum cookie passing.

## 3. Backend & Security Constraints
- ALL incoming backend queries must be validated using dedicated Laravel Form Request classes (`php artisan make:request`).
- Controllers must remain thin. Business logic belongs in Services, Actions, or Models.
- All database queries must use Eloquent or parameterized Query Builder functions to prevent SQL injection.
- Every Supabase table must have Row Level Security (RLS) enabled.