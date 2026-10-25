# Self-hosted PostgreSQL (replaces Supabase)

NaSeRy can run on a PostgreSQL 18 database on the same computer as the API, instead of the shared Supabase project. It's much faster: queries take under 1 ms instead of ~150 ms to Tokyo, and the full test suite runs in about 1 minute instead of 40. It also has no connection limit and no pausing after a week of inactivity.

| File | What |
|---|---|
| `00_supabase_compat.sql` | Recreates the few Supabase pieces the schema uses: the `postgres` login (non-superuser, BYPASSRLS), the `anon`/`authenticated`/`service_role` roles, and `auth.uid()`. **Never run it on Supabase.** |
| `setup-local-db.ps1` | One-time setup: creates the database in `C:\xampp\pgsql\data` (localhost:5433), builds the schema from `../supabase/0001…`, creates Laravel's tables, optionally copies all data from a backup, points `backend\.env` at it, and runs `nasery:doctor`. |
| `start-local-db.ps1` / `stop-local-db.ps1` | Start or stop it. |

## Setup (once)

Needs PostgreSQL 18 installed (we use its programs in `C:\Program Files\PostgreSQL\18\bin`; the installer's own Windows service isn't used).

1. **Take a fresh backup of the data you want to keep** (while `backend\.env` still points at Supabase, and the Supabase project is not paused):
   ```powershell
   cd backend
   C:\xampp\php8.5.10\php.exe artisan nasery:db:backup --reason=move-to-local
   ```
2. **Stop Apache and the queue worker.**
3. **Run the setup** with that backup (normal PowerShell window, not as Administrator):
   ```powershell
   powershell -ExecutionPolicy Bypass -File backend\database\local\setup-local-db.ps1 -FromBackup backend\storage\backups\<the file from step 1>.dump
   ```
   It asks for **two passwords**, which you type and nobody else sees:
   - `nasery_super`: the database administrator, for maintenance only. Keep it in a password manager.
   - `postgres`: the login the backend uses. It's written into `backend\.env` as `DB_PASSWORD`.

   It ends with `All checks passed.` Your old settings are kept in `backend\.env.supabase` (git-ignored).
4. **Start Apache and the queue worker again.**

## Every day
- After a reboot: `start-local-db.ps1`, then Apache, the queue worker and the frontend.
- Before shutting down: stop Apache and the worker, then `stop-local-db.ps1`.
- Server logs: `C:\xampp\pgsql\data\log\` (queries slower than 1 s are logged).

## Notes
- Only this computer can connect (`listen_addresses = 'localhost'`, password required). Never open port 5433 in the firewall or router.
- New migrations work exactly as before (`php artisan nasery:db:sql 0048_x.sql --apply`): the command uses whatever database `backend\.env` points at.
- Backups (`nasery:db:backup`) work too, and matter more now: this computer holds the only copy. Copy backups to another drive or cloud storage regularly.
- Switch back to Supabase: copy `backend\.env.supabase` over `backend\.env` and restart Apache and the worker.
