#!/bin/bash
# Runs ON the server every night (see /etc/cron.d/nasery-backup).
# One file per day with the database and everything people uploaded, kept for
# 7 days. To keep a copy off the server: bash scripts/nasery-pull-backup.sh
set -euo pipefail

BACKEND=/var/www/nasery/backend
DIR=/var/backups/nasery
STAMP="$(date +%Y%m%d-%H%M)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$DIR"
chmod 700 "$DIR"

PGPASSWORD="$(cat /root/nasery-db-super.txt)" /usr/lib/postgresql/18/bin/pg_dump \
	-h 127.0.0.1 -U nasery_super -d nasery --format=custom --schema=public --schema=laravel --file="$WORK/database.dump"
tar -czf "$WORK/uploads.tgz" -C "$BACKEND/storage/app" .

tar -cf "$DIR/nasery-$STAMP.tar" -C "$WORK" database.dump uploads.tgz
chmod 600 "$DIR/nasery-$STAMP.tar"

# Keep the newest 7.
ls -1t "$DIR"/nasery-*.tar | tail -n +8 | xargs -r rm -f
