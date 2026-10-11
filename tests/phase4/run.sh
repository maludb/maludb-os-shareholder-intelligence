#!/usr/bin/env bash
# Phase 4 — the two MCP servers, the five shares, app_roles, the gate, both walls, the registry: tests/phase4/run.sh
# A fresh SCRATCH database (si_dev40 — never the installed one), the application on :8901 (php -S, or SI_APP=apache: a real Apache with the rendered vhost — then the proofs
# also reach the servers through the vhost's own /mcp/records and /mcp/activity lines), a fake kernel (:8902 — run-facts, the chat and text doors), a fake MaluDB + MaluMail
# (:8903), and the servers under test on :8904 (records) and :8905 (activity). The fail-closed proofs stop the fake kernel for a moment. NOTHING REAL IS TOUCHED: no live
# service, Apache, unit, database or role password but the three scratch-database roles' (tests/setup_dev.sh gives them a scratch password).
#   RUN IT UNDER THE SHARED LOCK:  flock /tmp/si-proofs.lock tests/phase4/run.sh        (never flock inside this script: a nested flock deadlocks)
#   SI_PROOFS="gate wall" tests/phase4/run.sh     runs some, in order (the world is built by the first that needs it).  SI_KEEP=1 leaves the servers running; SI_STOP=1 stops them.
# Needs `sudo -n -u postgres`, php, and a Python venv with FastMCP (see mcp_servers.sh). Exit 0 only when every proof passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export SI_DEV_DB=${SI_DEV_DB:-si_dev40} SI_DEV_ENV=${SI_DEV_ENV:-/tmp/si-dev40.env} SI_DEV_STATE=${SI_DEV_STATE:-/tmp/si-dev40-state}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
mkdir -p "$SI_DEV_STATE"
stop_all() {
  "$ROOT/tests/phase4/mcp_servers.sh" stop >/dev/null 2>&1; "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1
}
if [ "${SI_STOP:-0}" = 1 ]; then stop_all; echo stopped; exit 0; fi
for port in 8901 8902 8903 8904 8905 8906 8907; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
rm -f "$SI_DEV_STATE"/world*.json
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sudo -n -u postgres psql -q -c "ALTER DATABASE $SI_DEV_DB SET pgaudit.log = 'none'" >/dev/null 2>&1       # this scratch database alone — never the server's setting
sed -i 's#^MALUMAIL_API_URL=.*#MALUMAIL_API_URL=http://127.0.0.1:8903#' "$SI_DEV_ENV"
rm -rf "$SI_DEV_STATE/storage"; mkdir -p "$SI_DEV_STATE/storage"; sed -i "s#^SI_STORAGE_DIR=.*#SI_STORAGE_DIR=$SI_DEV_STATE/storage#" "$SI_DEV_ENV"
cleanup() {
  [ "${SI_KEEP:-0}" = 1 ] && { echo "== left running (SI_KEEP=1): SI_STOP=1 bash $0 stops them"; return; }
  stop_all
  sudo -n -u postgres dropdb --if-exists "$SI_DEV_DB" >/dev/null 2>&1
}
trap cleanup EXIT INT TERM
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
set -a; . "$SI_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$SI_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$SI_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$SI_DEV_STATE/malumail.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
sudo -n -u postgres psql -q -v ON_ERROR_STOP=1 -d "$SI_DEV_DB" -c "UPDATE si_settings SET business_name = 'Kinetic Seas', market_holidays = '{2024-03-29}'" || exit 1
status=0
echo "== world"; php tests/phase4/world.php || status=1
# after servers.sh (its start stops every pid file in the state directory): the MCP servers start on the finished world
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
: > "$SI_DEV_STATE/p4-called.txt"
proofs=${SI_PROOFS-gate wall reads shares activity registry coverage}
for p in $proofs; do
  echo "== $p"
  php "tests/phase4/$p.php" || status=1
done
logs=$(ls "$SI_DEV_STATE/app.log" "$SI_DEV_STATE/apache/apache.err" 2>/dev/null)
if [ -n "$logs" ] && grep -q 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; then echo "  FAIL the application logged a PHP warning:"; grep -h -m5 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; status=1; else echo "  ok   no PHP warning in the application's log"; fi
if grep -q -i 'traceback' "$SI_DEV_STATE"/mcp-*.log 2>/dev/null; then echo "  FAIL a server logged a traceback:"; grep -h -i -m3 -B2 -A8 'traceback' "$SI_DEV_STATE"/mcp-*.log | head -30; status=1; else echo "  ok   no traceback in the servers' logs"; fi
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
