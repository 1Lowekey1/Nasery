# Engineering Guidebook: Vue 3 + Laravel + Supabase Monorepo

This document serves as our team’s definitive ground truth for workspace directory initialization, automated AI coding assistant boundaries, and our core stateful authentication implementation pattern.

---

## 1. Repository Directory Structure
Our workspace is organized as a unified monorepo. This allows us to manage both frontend and backend configurations under a single Git history while keeping codebases logically distinct.

```text
├── .github/
│   └── copilot-instructions.md  # Drives inline editor AI suggestions
├── backend/                      # Laravel API (System Gatekeeper)
│   ├── .env.example              # Pre-configured database connection template
│   └── ...
├── frontend/                     # Vue 3 UI (Vite + Composition API)
│   ├── .env.example              # Pre-configured client API endpoints
│   └── ...
├── ai-rules.md                   # Global context profile for ChatGPT/Claude
└── .gitignore                    # Shared safeguard blocking environment leakages