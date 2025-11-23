#!/usr/bin/env bash

# Remote server info
REMOTE_USER="mentorcorps"
REMOTE_PASS="wJAzNuve"
REMOTE_HOST="s417.sureserver.com"
REMOTE_PATH="/www/template/wp-content/plugins/mpro-ld-summary"

# Local plugin directory (this repo)
LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Deploying from:"
echo "  $LOCAL_DIR"
echo "to:"
echo "  $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"
echo

lftp -u "$REMOTE_USER","$REMOTE_PASS" "$REMOTE_HOST" <<EOF
set ftp:passive-mode on

# Dry run first time: remove --dry-run after you trust it
mirror -R \
--delete \
--verbose \
--exclude-glob '.git' \
--exclude-glob '.git/*' \
--exclude-glob '.gitignore' \
--exclude-glob 'deploy-staging.sh' \
"$LOCAL_DIR" "$REMOTE_PATH"


bye
EOF

echo
echo "Deploy complete."
