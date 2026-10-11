#!/usr/bin/env bash
# Slice 9's proofs (docs/build-specs/agents-k7-api.md "Proof"), EACH ON A FRESH SCRATCH DATABASE: tests/phase3/slice9/run.sh [proof…]
#   SI_APP=apache tests/phase3/slice9/run.sh  serves the app from a real Apache with the rendered vhost.
#   Run under the shared lock: flock /tmp/si-proofs.lock tests/phase3/slice9/run.sh
#   Servers: the app :8901, the fake kernel :8902 (the chat endpoint, the texts), the fake MaluDB + MaluMail :8903. Nothing is fetched, no real mail or text is sent: the SMOKE store of tests/phase2/lib.php
#   (seed_store) and tests/phase3/slice9/lib.php (seed9) are the data.
#   Proofs: proposals dispatch outbox shares keys api json browser (all by default), then the registry check.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export SI_DEV_DB=${SI_DEV_DB:-si_dev39} SI_DEV_ENV=${SI_DEV_ENV:-/tmp/si-dev39.env} SI_DEV_STATE=${SI_DEV_STATE:-/tmp/si-dev39-state} SHOTS=${SHOTS:-/tmp/si-shots-s9}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed; the proofs are for a scratch clone"; exit 2; }
for port in 8901 8902 8903 8907; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
mkdir -p "$SHOTS" "$SI_DEV_STATE"
cd "$ROOT"
stop_all() { "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1; sleep 0.3; }
trap 'stop_all; sudo -n -u postgres dropdb --if-exists "$SI_DEV_DB" >/dev/null 2>&1' EXIT
# A fresh scratch database (pgaudit off for it alone — never the server's setting), a fresh store, the servers restarted on its environment, the
# directory synced; Good Friday 2024-03-29 a market holiday (the Friday pack proof runs on 2024-04-05).
fresh() {
  stop_all
  "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
  sudo -n -u postgres psql -q -c "ALTER DATABASE $SI_DEV_DB SET pgaudit.log = 'none'" >/dev/null 2>&1
  sed -i 's#^MALUMAIL_API_URL=.*#MALUMAIL_API_URL=http://127.0.0.1:8903#' "$SI_DEV_ENV"
  rm -rf "$SI_DEV_STATE/storage"; mkdir -p "$SI_DEV_STATE/storage"; sed -i "s#^SI_STORAGE_DIR=.*#SI_STORAGE_DIR=$SI_DEV_STATE/storage#" "$SI_DEV_ENV"
  "$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
  set -a; . "$SI_DEV_ENV"; set +a
  export FAKE_KERNEL_STATE="$SI_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$SI_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$SI_DEV_STATE/malumail.log"
  php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
  sudo -n -u postgres psql -q -v ON_ERROR_STOP=1 -d "$SI_DEV_DB" -c "UPDATE si_settings SET business_name = 'Kinetic Seas', market_holidays = '{2024-03-29}'" || exit 1
}
status=0
PROOFS=${*:-proposals dispatch outbox shares keys api json browser}
for p in $PROOFS; do
  fresh
  echo "== $p"
  if [ "$p" = browser ]; then node tests/phase3/slice9/browser.mjs || status=1; else php "tests/phase3/slice9/$p.php" || status=1; fi
  if grep -qiE "PHP (warning|notice|fatal|deprecated)|uncaught" "$SI_DEV_STATE/app.log" 2>/dev/null; then echo "  FAIL the server log has a PHP warning or error:"; grep -iE "PHP (warning|notice|fatal|deprecated)|uncaught" "$SI_DEV_STATE/app.log" | head -3; status=1; fi
done
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry is stale"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 9 PROOFS PASSED" || echo "SOME SLICE 9 PROOFS FAILED"
exit $status
