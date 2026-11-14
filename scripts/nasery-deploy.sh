#!/bin/bash
# ---------------------------------------------------------------------
#  NaSeRy: send the code in this folder to the live server (nasery.bid).
#  Run from Git Bash:   bash scripts/nasery-deploy.sh
#
#    --skip-build        reuse frontend/.output/public (backend-only change)
#    --sql FILE.sql      also apply a migration from backend/database/supabase,
#                        after a database backup on the server (repeatable)
#
#  Sends the working folder as it is, so nothing has to be committed first.
#  Never sends or overwrites: backend/.env, storage (uploads, logs, backups),
#  vendor. The server's database stays the real one.
# ---------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
KEY="$HOME/.ssh/nasery-lightsail.pem"
SERVER="ubuntu@54.252.246.86"
PUBLIC_URL="https://nasery.bid"

BUILD=1
MIGRATIONS=()
while [ $# -gt 0 ]; do
	case "$1" in
		--skip-build) BUILD=0 ;;
		--sql) shift; MIGRATIONS+=("$1") ;;
		*) echo "Unknown option: $1"; exit 1 ;;
	esac
	shift
done

for f in "${MIGRATIONS[@]:-}"; do
	if [ -n "$f" ] && [ ! -f "$ROOT/backend/database/supabase/$f" ]; then echo "Migration not found: $f"; exit 1; fi
done

SSH=(ssh -i "$KEY" -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=30 "$SERVER")

if [ "$BUILD" = 1 ]; then
	echo "== Building the website"
	(
		cd "$ROOT/frontend"
		# Relative addresses: the site and /api share one origin.
		# Git Bash would turn "/api/v1" into "C:/Program Files/Git/api/v1" for npm; keep it as written.
		export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' MSYS2_ENV_CONV_EXCL='NUXT_PUBLIC_'
		export NUXT_PUBLIC_API_BASE=/api/v1 NUXT_PUBLIC_API_BASE_URL=/api/v1 NUXT_PUBLIC_BACKEND_URL=/ NUXT_PUBLIC_APP_BASE_URL="$PUBLIC_URL"
		npm run generate >/dev/null
	)
fi
[ -f "$ROOT/frontend/.output/public/200.html" ] || { echo "No website build found."; exit 1; }
# Never send a build that points at a folder on this PC instead of the site's own /api.
if ! grep -q 'apiBase:"/api/v1"' "$ROOT/frontend/.output/public/200.html"; then
	echo "STOPPED: the website build has the wrong API address. Nothing was sent."
	exit 1
fi

echo "== Packing"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
tar -czf "$WORK/backend.tgz" -C "$ROOT" \
	--exclude=backend/vendor --exclude='backend/.env*' --exclude=backend/storage \
	--exclude='backend/bootstrap/cache/*.php' --exclude=backend/tests \
	--exclude=backend/node_modules --exclude='backend/.phpunit*' backend
tar -czf "$WORK/web.tgz" -C "$ROOT/frontend/.output" public

echo "== Sending"
"${SSH[@]}" 'sudo rm -rf ~/deploy && mkdir -p ~/deploy && chmod 700 ~/deploy'
scp -q -i "$KEY" -o BatchMode=yes "$WORK/backend.tgz" "$WORK/web.tgz" "$ROOT/scripts/server-update.sh" "$ROOT/scripts/server-backup.sh" "$SERVER:deploy/"

echo "== Updating the server"
"${SSH[@]}" "sudo bash ~/deploy/server-update.sh ${MIGRATIONS[*]:-}"

echo "== Checking the public site"
sleep 2
for path in / /login /api/v1/public-events; do
	code="$(curl -s -o /dev/null -w '%{http_code}' "$PUBLIC_URL$path")"
	echo "  $code $path"
	[ "$code" = 200 ] || { echo "STOPPED: $path answered $code"; exit 1; }
done
echo "Live at $PUBLIC_URL"
