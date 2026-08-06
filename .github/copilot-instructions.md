# GitHub Copilot Repository Instructions

## Tech Stack Declarations
- Frontend: Vue 3 (Composition API, `<script setup>`, TypeScript/JavaScript)
- Backend: Laravel (Strictly typed API controllers, returning JsonResponse or API Resources)
- Database: PostgreSQL via Supabase

## Copilot Behavioral Directives
- Always generate thin controllers. Business logic should live in Services, Actions, or Models.
- When generating Laravel controllers, always generate a corresponding FormRequest class snippet for validation.
- Always include explicit data types for function arguments and return types.
- Never suggest storing raw API keys or secrets in frontend components; always pull from environment variables (`import.meta.env`).
- Prioritize clean, readable, single-responsibility functions over dense, complex code blocks.
- Avoid introducing external libraries unless natively supported by the project ecosystem.
