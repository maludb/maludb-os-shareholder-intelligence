#!/usr/bin/env bash
# tests/phase2/servers.sh start|stop — the proofs' servers on loopback, all from the scratch environment ($SI_DEV_ENV):
#   the application on :8901, a fake kernel on :8902, a fake MaluDB + MaluMail on :8903. The application is `php -S` with
#   tests/dev_router.php by default; with SI_APP=apache it is a REAL Apache (as your user, mod_php) serving deploy/apache-shareholder_intelligence.conf
#   rendered the way the kernel's installer renders it (the public vhost moved to :8901, the internal one on :8907) — the proofs then
#   exercise the vhost's own rewrites (/sso, the canonical URLs, /api blocked, the MCP proxies). State: $SI_DEV_STATE (default
#   /tmp/si-dev-state) holds the pid files, the fake kernel's state file and the MaluDB log.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${SI_DEV_ENV:-/tmp/si-dev.env}
STATE=${SI_DEV_STATE:-/tmp/si-dev-state}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  pkill -f 'php .*-S 127.0.0.1:890[123]' 2>/dev/null || true       # the built-in server's forked workers outlive their parent
  [ -f "$STATE/apache/main.conf" ] && apache2 -f "$STATE/apache/main.conf" -k stop 2>/dev/null || true
  sleep 0.3
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    export FAKE_KERNEL_STATE="$STATE/kernel.json" FAKE_MALUDB_LOG="$STATE/maludb.log" FAKE_MALUMAIL_LOG="$STATE/malumail.log"
    : > "$FAKE_MALUDB_LOG"; : > "$FAKE_MALUMAIL_LOG"
    php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
    php -S 127.0.0.1:8902 "$ROOT/tests/fake_kernel.php" >"$STATE/kernel.log" 2>&1 & echo $! > "$STATE/kernel.pid"
    php -S 127.0.0.1:8903 "$ROOT/tests/fake_maludb.php" >"$STATE/fake.log" 2>&1 & echo $! > "$STATE/fake.pid"
    if [ "${SI_APP:-php}" = "apache" ]; then
      mkdir -p "$STATE/apache"
      python3 - "$ROOT" "$STATE/apache" <<'PY'
import sys
root, out = sys.argv[1], sys.argv[2]
v = open(root + '/deploy/apache-shareholder_intelligence.conf').read()
for k, val in {'APP_FQDN': 'shareholders.subello.com', 'APP_DIR': root, 'APP_INTERNAL_PORT': '8907', 'MCP_RECORDS_PORT': '8904', 'MCP_ACTIVITY_PORT': '8905'}.items():
    v = v.replace('{{%s}}' % k, val)
assert '{{' not in v, 'unfilled placeholder in the vhost template'
v = v.replace('<VirtualHost *:80>', '<VirtualHost *:8901>')
open(out + '/site.conf', 'w').write(v)
open(out + '/main.conf', 'w').write('ServerRoot "/etc/apache2"\nDefaultRuntimeDir %s\nPidFile %s/apache.pid\nErrorLog %s/apache.err\nServerName localhost\nLogLevel warn\n'
    'Include /etc/apache2/mods-enabled/*.load\nInclude /etc/apache2/mods-enabled/*.conf\nDefine APACHE_LOG_DIR %s\nListen 8901\nInclude %s/site.conf\n' % ((out,) * 5))
PY
      # mod_php reads the application's environment from the process: the scratch .env is exported above, so the vhost needs no SetEnv.
      apache2 -f "$STATE/apache/main.conf" -k start
    else
      ( cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=64M -d post_max_size=72M -S 127.0.0.1:8901 -t html tests/dev_router.php >"$STATE/app.log" 2>&1 & echo $! > "$STATE/app.pid" )
    fi
    for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8901/api/v1/health && break; sleep 0.2; done
    echo "servers up (app :8901 ${SI_APP:-php}, fake kernel :8902, fake MaluDB + MaluMail :8903)" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
