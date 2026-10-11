#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres`; recreates $SI_DEV_DB, default si_dev0)
# 1. drops and creates the scratch database; 2. applies db/0*.sql in order as postgres (the same files the installer applies —
#    db/001–005 alone are enough for the kit's proof; the schema's files follow as they are written); 3. leaves the cluster roles'
#    passwords ALONE when the app is installed (reads them from config/.env); else gives them a scratch password (the kernel's
#    installer sets fresh ones at `apply`); 4. writes the proofs' environment to $SI_DEV_ENV (default /tmp/si-dev0.env, mode 600) —
#    app/bootstrap.php reads that file instead of config/.env when SI_DEV_ENV is set (never in production). Nothing here is
#    committed or installed. 
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${SI_DEV_DB:-si_dev0}
ENVF=${SI_DEV_ENV:-/tmp/si-dev0.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
UPTO=${SI_DEV_UPTO:-999}      # SI_DEV_UPTO=005 applies db/001–005 only (the kit's own proof while later files are being written)
for f in "$ROOT"/db/0*.sql; do n=$(basename "$f" | cut -c1-3); [ "$n" -le "$UPTO" ] 2>/dev/null || continue; $PSQL -d "$DB" -f "$f" >/dev/null || { echo "FAILED: $f"; exit 1; }; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
# A worktree (or a second clone) of an INSTALLED Shareholder Intelligence has no config/.env of its own but shares the cluster's three roles
# with the install: reuse the install's passwords (SI_LIVE_ENV names its config/.env; /srv/apps/shareholder_intelligence is the install
# path), NEVER reset them — a reset takes the live application down. An install whose config/.env cannot be read stops this script.
if ! { [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ]; }; then
  for cand in "${SI_LIVE_ENV:-}" /srv/apps/shareholder_intelligence/config/.env; do
    [ -n "$cand" ] && [ -e "$cand" ] || continue
    if [ ! -r "$cand" ]; then echo "ABORT: $cand exists but cannot be read — the installed roles' passwords would be reset"; exit 2; fi
    if grep -q '^DB_PASSWORD=.' "$cand"; then LIVEENV=$cand; break; fi
  done
fi
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in shareholder_intelligence_rw shareholder_intelligence_records_ro shareholder_intelligence_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Shareholder Intelligence"
APP_KEY=shareholder_intelligence
APP_URL=http://127.0.0.1:8901
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=shareholder_intelligence_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=shareholder_intelligence_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=shareholder_intelligence_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=8901
MCP_RECORDS_PORT=8904
MCP_ACTIVITY_PORT=8905
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:8902
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:8903
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:8906
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=shareholders@example.invalid
MAIL_FROM_NAME="Shareholder Intelligence"
SI_STORAGE_DIR=
SI_MAX_UPLOAD_BYTES=
SI_WKHTMLTOPDF=
SI_SECRETS_KEY=$(openssl rand -hex 32)
MALUDB_DC_API_URL=
MALUDB_DC_API_TOKEN=
ENV
echo "scratch database $DB ready; environment in $ENVF"
