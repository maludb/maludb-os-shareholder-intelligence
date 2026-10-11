#!/usr/bin/env bash
# tests/phase4/mcp_servers.sh start|stop|variant|venv — the two MCP servers on SCRATCH loopback ports from the scratch environment ($SI_DEV_ENV):
# records :8904, activity :8905 (never the installed application's ports 8845/8846 or its services). Pid files live in $SI_DEV_STATE. `variant notoken` is a third
# records server on :8906 with no OS_APPLICATION_TOKEN (it cannot ask the kernel: the fail-closed proof); `variant stop` ends it.
# The Python is the application's own mcp/venv (python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt; deploy/ROOT_STEPS.sh does it for the
# installed copy), else $SI_MCP_VENV, else the main clone's venv or a sibling application's that already holds FastMCP, asyncpg, httpx and uvicorn — nothing is installed here.
# The records server is started WITHOUT the MaluDB token in its environment (it never needs it); the activity server keeps it (record_episodes reads the memory).
# Run under the shared lock: flock /tmp/si-proofs.lock tests/phase4/run.sh — this script never takes it.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${SI_DEV_ENV:-/tmp/si-dev40.env}
STATE=${SI_DEV_STATE:-/tmp/si-dev40-state}
mkdir -p "$STATE"
VENV=
for c in "${SI_MCP_VENV:-}" "$ROOT/mcp/venv" /srv/apps/shareholder_intelligence/mcp/venv /srv/apps/inventory/mcp/venv /srv/apps/knowledge/mcp/venv /srv/apps/doccloud/mcp/venv /srv/apps/spaces/mcp/venv; do
  [ -n "$c" ] && [ -x "$c/bin/python" ] && "$c/bin/python" -c 'import mcp, asyncpg, httpx, uvicorn' 2>/dev/null && { VENV=$c; break; }
done
[ -n "$VENV" ] || { echo "no venv with FastMCP, asyncpg, httpx and uvicorn found" >&2; exit 1; }
PY="$VENV/bin/python"
stop() {
  for f in "$STATE"/mcp-*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  for i in $(seq 1 30); do ss -ltn 2>/dev/null | grep -qE ':(8904|8905) ' || break; sleep 0.2; done
  fuser -k -n tcp 8904 >/dev/null 2>&1 || true; fuser -k -n tcp 8905 >/dev/null 2>&1 || true      # by port, never by a command-line pattern
}
case "${1:-}" in
  stop) stop; fuser -k -n tcp 8906 >/dev/null 2>&1 || true ;;
  variant)
    fuser -k -n tcp 8906 >/dev/null 2>&1 || true
    if [ "${2:-}" != stop ]; then
      set -a; . "$ENVF"; set +a
      case "${2:-}" in
        notoken) ( cd "$ROOT/mcp" && exec env -u MALUDB_API_TOKEN -u MALUDB_API_URL -u OS_APPLICATION_TOKEN MCP_RECORDS_PORT=8906 MCP_ACTIVITY_PORT=8905 "$PY" "$ROOT/mcp/records_server.py" >"$STATE/mcp-variant.log" 2>&1 ) & echo $! > "$STATE/mcp-variant.pid" ;;
        *) echo "variant notoken|stop" >&2; exit 2 ;;
      esac
      for i in $(seq 1 60); do curl -s -o /dev/null -m 1 "http://127.0.0.1:8906/mcp" && break; sleep 0.2; done
    fi ;;
  venv) echo "$VENV" ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    ( cd "$ROOT/mcp" && exec env -u MALUDB_API_TOKEN -u MALUDB_API_URL MCP_RECORDS_PORT=8904 MCP_ACTIVITY_PORT=8905 "$PY" "$ROOT/mcp/records_server.py" >"$STATE/mcp-records.log" 2>&1 ) & echo $! > "$STATE/mcp-records.pid"
    ( cd "$ROOT/mcp" && exec env MCP_RECORDS_PORT=8904 MCP_ACTIVITY_PORT=8905 "$PY" "$ROOT/mcp/activity_server.py" >"$STATE/mcp-activity.log" 2>&1 ) & echo $! > "$STATE/mcp-activity.pid"
    for p in 8904 8905; do
      for i in $(seq 1 60); do curl -s -o /dev/null -m 1 "http://127.0.0.1:$p/mcp" && break; sleep 0.2; done
    done
    echo "mcp servers up (records :8904, activity :8905; python $VENV)" ;;
  *) echo "usage: $0 start|stop|variant|venv" >&2; exit 2 ;;
esac
