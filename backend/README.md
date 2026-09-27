# NaSeRy API (Laravel 13)

Backend for NaSeRy: Nuxt PWA → this Laravel API (`/api/v1`) → Supabase PostgreSQL.

## Requirements

- **PHP 8.5.10** (pinned in `composer.json`; any other version fails at boot with a clear
  message) with `pdo_pgsql`, `openssl`, `mbstring`, `intl`, `sodium`, `curl`, `zip`, `fileinfo`
- Composer 2
- PostgreSQL client tools (`pg_dump`) for backups

### Using PHP 8.5.10 without changing your machine's default PHP

The machine default can stay on another version (e.g. XAMPP's 8.2). NaSeRy picks 8.5.10 via:

- **VS Code**: open `Nasery.code-workspace`; its terminals get `C:\xampp\php8.5.10` first on PATH,
  so plain `php` / `composer` work there.
- **Any other terminal** (inside `backend/`): use the wrappers `.\php` and `.\composer`
  (they call `C:\xampp\php8.5.10\php.exe`; override with the `NASERY_PHP` / `NASERY_COMPOSER`
  environment variables if yours is elsewhere).
- Hosting (Railway or other) must also run PHP 8.5.

## Setup

```bash
.\composer install
cp .env.example .env         # fill DB_* with your Supabase credentials
.\php artisan key:generate
.\php artisan migrate        # only creates the private `laravel` schema tables
.\php artisan nasery:doctor  # must pass
.\php artisan serve
```

The domain schema is managed as raw SQL in `database/supabase/` (see its README).

## Everyday commands

| Command | Purpose |
|---|---|
| `.\composer test` | pg_dump backup, then the test suite (rolled-back transactions) |
| `.\php artisan nasery:doctor` | Verify PHP, schema version and RLS security setup |
| `.\php artisan nasery:db:backup --reason=x` | Backup to `storage/backups/` |
| `.\php artisan queue:work` | Process queued jobs (mail, notifications, exports) |
| `.\php vendor/bin/pint` | Code style |

Architecture and conventions: `docs/B0_FOUNDATION.md`.
