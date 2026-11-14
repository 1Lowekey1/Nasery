#!/bin/bash
# ---------------------------------------------------------------------
#  Copy the newest nightly backup from the server to this computer.
#  Run from Git Bash:   bash scripts/nasery-pull-backup.sh
#  It contains personal data and uploaded documents: keep it private, and
#  never put it in git or a shared folder.
# ---------------------------------------------------------------------
set -euo pipefail

KEY="$HOME/.ssh/nasery-lightsail.pem"
SERVER="ubuntu@54.252.246.86"
DEST="$HOME/nasery-backups"

mkdir -p "$DEST"
SSH=(ssh -i "$KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SERVER")
NEWEST="$("${SSH[@]}" 'sudo ls -1t /var/backups/nasery/nasery-*.tar | head -1')"
[ -n "$NEWEST" ] || { echo "No backup on the server yet."; exit 1; }

NAME="$(basename "$NEWEST")"
"${SSH[@]}" "sudo cat '$NEWEST'" > "$DEST/$NAME"
echo "Saved $DEST/$NAME ($(du -h "$DEST/$NAME" | cut -f1))"
