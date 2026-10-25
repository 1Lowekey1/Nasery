# Handing your changes back to the main instance

**For:** any team member (and their Claude instance) who changed NaSeRy on their own laptop.
**Goal:** put your work on its **own branch**, with a **handoff document** that explains it, so Nathan's main Claude instance can compare it with the main code and merge it safely.

> **Member:** give this whole file to your Claude instance and say:
> *"Follow MEMBER_HANDOFF_INSTRUCTIONS.md. Stop and ask me before committing and before pushing."*
> Then review what it writes before you let it push.

---

## Rules for the Claude instance (read first)

1. **Do not pull, merge, rebase or reset.** Don't run `git pull`, `git merge`, `git rebase`, `git reset --hard` or `git checkout -- .` on this laptop. The member's work is uncommitted, and any of these can lose it or mix it with newer code. The main instance does the merging.
2. **Do not push to `main` or `frontend-merge`.** Push only the new `handoff/...` branch you create below.
3. **Never write secrets** into any file, commit message or chat:
   - no `.env` values, passwords, API keys or tokens, and no database connection strings
   - refer to settings **by name only**, e.g. "added `NUXT_PUBLIC_LOCATION_IQ_API_KEY`"
4. **Do not change the shared database now.** That means:
   - no migrations (`nasery:db:sql … --apply`)
   - no reseed (`nasery:demo:seed`)
   - no purge
   - no manual SQL in the Supabase dashboard

   Everyone shares one Supabase database. If something *was* already changed there, **document it** (section 4 of the template); don't undo it.
5. **Ask the member before committing and before pushing.** Show them `git status` and the handoff document first.
6. Use PHP by full path if you need it: `C:\xampp\php8.5.10\php.exe`. Don't run the Laravel Boost / php.new install steps from `backend/CLAUDE.md`.

---

## Steps

### 1. Stop everything that's running
Stop Apache (API), the queue worker and `npm run dev` (Ctrl+C in each window).

### 2. Record where the work started
```powershell
git branch --show-current
git rev-parse HEAD
git log --oneline -5
git status
```
Keep the branch name and the commit hash (`rev-parse`); they go into the handoff document.

### 3. Create the handoff branch (keeps all uncommitted changes)
```powershell
git switch -c handoff/<member-name>-<YYYY-MM-DD>
```
Example: `handoff/rylle-2026-10-03`. Switching to a **new** branch keeps every uncommitted change exactly as it is.

### 4. Undo personal-only edits
- `backend/server/apache-local.conf`: if the **only** change is the `NASERY_ROOT` path (the laptop's folder), don't commit it:
  ```powershell
  git restore backend/server/apache-local.conf
  ```
  (Re-apply your path after pushing, or use `git update-index --skip-worktree backend/server/apache-local.conf` before editing it again.) If you changed **other** settings in that file on purpose, keep them and explain them in the handoff document.
- Undo any debug-only edits (temporary `console.log`, test values, commented-out code) unless they're intentional.

### 5. Write the handoff document
Create **`docs/handoff/<YYYY-MM-DD>-<member-name>.md`** using the **template below**. Fill in **every** section; write "None" where nothing applies. This is how the main instance learns what you did and why. Be specific: file paths, what changed, and the reasoning behind decisions.

### 6. Check that nothing private is included
```powershell
git status --untracked-files=all
```
None of these may appear. They're git-ignored, but check anyway, since someone may have force-added one:

| Must NOT be committed | Why |
|---|---|
| `backend/.env`, `frontend/.env`, any `.env.*` except `.env.example` | secrets |
| `backend/storage/backups/*`, `*.dump`, `*.sql` dumps of data | real user data |
| `backend/storage/logs/*` | may contain personal data |
| `Context Folder/` | kept out of Git on purpose |
| `node_modules/`, `backend/vendor/` | installed packages |
| `.claude/`, `CLAUDE.md` files your instance created for itself | personal tool settings (put what matters in the handoff doc instead) |

If new **SQL migration** files were created in `backend/database/supabase/`, they **are** committed (with their `.down.sql`). See section 4 of the template.

### 7. Commit (after the member approves)
```powershell
git add -A
git status          # review the list once more
git commit -m "Handoff from <member-name>: <one-line summary>"
```

### 8. Push the branch (after the member approves)
```powershell
git push -u origin handoff/<member-name>-<YYYY-MM-DD>
```

### 9. Tell Nathan
Send Nathan the branch name. Don't open a pull request or merge anything yourself; the main instance compares and merges.

---

## Handoff document template

Copy this into `docs/handoff/<YYYY-MM-DD>-<member-name>.md` and fill it in.

```markdown
# Handoff: <member name> — <YYYY-MM-DD>

## 1. Summary
<2–5 sentences: what was worked on and why (feature, bug, adviser comment…).>

## 2. Starting point
- Branch the work started from: <e.g. frontend-merge>
- Commit hash it started from: <output of git rev-parse HEAD in step 2>
- Pulled/updated before starting? <yes (when) / no>

## 3. Changes by area
For each change: file path(s), what changed, why. Group them:

### Frontend (frontend/app/…)
- `pages/...vue` — <what and why>

### Backend (backend/app/…, routes, config)
- `app/Services/...php` — <what and why>

### Tests
- <new/changed test files, and whether they were run + result (pass/fail counts)>

### Docs
- <docs updated, e.g. DEMO_SETUP_GUIDE.md, backend/docs/Bxx_*.md>

## 4. Database changes  ← most important section
- New migration files: <e.g. backend/database/supabase/0047_x.sql + 0047_x.down.sql, or "None">
  (If the number might clash with someone else's, say so; the main instance will renumber.)
- Were they APPLIED to the shared Supabase database? <no / yes — when, by whom, backup file name if any>
- Any changes made WITHOUT a migration file (Supabase dashboard, SQL editor, tinker, manual edits)?
  <None / describe EXACTLY what was run or changed; write the SQL here — no data values that are personal>
- Data changes: <accounts created (e-mails only if they are @demo.nasery.test / @nasery.test), records added/deleted, reseeds or purges run, and when>

## 5. Settings and dependencies
- New/changed `.env` settings (NAMES ONLY, never values): <e.g. NUXT_PUBLIC_LOCATION_IQ_API_KEY in frontend/.env>
- New Composer packages: <name + why, or None>
- New npm packages: <name + why, or None>
- Apache config changes (other than NASERY_ROOT): <None / what + why>

## 6. Decisions and context
- Decisions made with the member (and the reason for each): <…>
- Things the Claude instance assumed or wasn't sure about: <…>
- Anything that differs from DEMO_SETUP_GUIDE.md or the existing docs: <…>

## 7. Status
- Done and verified (how: browser test / tests / manual): <…>
- Unfinished / known issues / TODO: <…>

## 8. How to check it
<Step-by-step: which account to sign in as (e.g. maria@demo.nasery.test), which page, what should happen.>
```

---

## After you push

- Keep your laptop as it is until Nathan confirms the merge. Don't delete the branch or the folder.
- When the merge is done, Nathan will tell you which branch to switch to and whether to run anything, e.g. `composer install`, `npm install`, or re-applying your `NASERY_ROOT` path.
