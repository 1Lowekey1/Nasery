# Engineering Guidebook: Nuxt 3 + Laravel + Supabase Monorepo

This document serves as our team’s definitive ground truth for workspace directory initialization, automated AI coding assistant boundaries, and our core stateful authentication implementation pattern.

---

## 1. Repository Directory Structure
Our workspace is organized as a unified monorepo:

```text
├── .github/
│   └── copilot-instructions.md  # Drives inline editor AI suggestions
├── backend/                      # Laravel API (System Gatekeeper)
│   ├── .env.example              # Pre-configured database connection template
│   └── ...
├── frontend/                     # Nuxt 3 App (Pages, Auto-imports, $fetch)
│   ├── nuxt.config.ts            # SPA mode & runtime API endpoints
│   ├── .env.example              # Pre-configured client environment keys
│   └── ...
├── ai-rules.md                   # Global context profile for AI assistants
└── .gitignore                    # Shared safeguard blocking environment leakages