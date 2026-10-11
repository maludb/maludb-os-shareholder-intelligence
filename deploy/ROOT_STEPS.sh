#!/usr/bin/env bash
# Shareholder Intelligence — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0
# (2026-10-09); brought up to date at the end of Phase 4 (2026-10-11). NOTHING HERE HAS BEEN RUN: the proofs use a scratch database, php -S and
# fixtures, and never touch an installed application, Apache, systemd, EDGAR or FINRA.
#   bash deploy/ROOT_STEPS.sh              prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply   runs the steps with no decision in them: 0 and 2b (the ports, the storage directory)
# Steps 1, 2, 3 and 4 are printed and never run: they are the installer's, the owner's, or put a secret somewhere.
set -euo pipefail
APP=/srv/apps/shareholder_intelligence
KERNEL=/var/www
MODE=${1:-plan}
STORAGE=${SI_STORAGE_DIR:-$APP/storage}   # the uploaded files kept whole, import work, exports, the rendered packs: under the install by default
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (Help Desk's lesson of 2026-10-02). A config/.env holding ONLY the three
#    ports and SI_SECRETS_KEY: the installer reads them, fills every other required key itself, and sets fresh role passwords because
#    DB_PASSWORD is absent. Without this file the installer picks the first free port (8111) and leaves the MCP ports and the key empty
#    (its plan says so). SI_SECRETS_KEY (64 hex characters, the box key the sources' credentials are sealed under) is generated ONCE
#    here and never replaced — a new key makes every stored credential unreadable. Nothing else by hand before apply — except
#    SI_STORAGE_DIR when the files go on a mount.
echo "== 0. Pin the ports (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  run bash -c "mkdir -p '$APP/config' && printf 'APP_INTERNAL_PORT=8192\nMCP_RECORDS_PORT=8845\nMCP_ACTIVITY_PORT=8846\nSI_SECRETS_KEY=%s\n' \"\$(openssl rand -hex 32)\" > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
  if [ "$STORAGE" != "$APP/storage" ]; then run bash -c "echo 'SI_STORAGE_DIR=$STORAGE' >> '$APP/config/.env'"; fi
else
  echo "   (config/.env exists — leaving it alone)"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost|config)'   -> ports done, no {{...}} unfilled, no key "left empty"

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT): database and roles, config/.env (the rest of the keys; SI_SECRETS_KEY is step 0's), the vhost, the units, the registration (catalog kind ours — the
#    row K34 seeded, db/178 —, /sso and /sso/logout, scope_kind none), the application token, skills imported, approval policies,
#    the installing super-admin's admin grant. --grant-standing-departments gives every standing department the Analyst role (key
#    user) — usually NOT wanted: grant Finance (or the IR department) instead and the board as Viewers. --hire-agents hires the
#    expert and the Surveillance Analyst. Run it twice: the roles are read from app_roles only once the records MCP answers (Phase 4).
echo "== 2. apply (ROOT) — twice: the second run reads the roles from the records MCP the first one started"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 2b. THE STORAGE DIRECTORY (ROOT; idempotent; before the first upload). The uploaded files kept whole (uploads/), import work
#     (imports/), exports (exports/) and the rendered packs (reports/) live under the install ($APP/storage) or on the mount
#     SI_STORAGE_DIR names. Owned by www-data, mode 750 — never under html/.
echo "== 2b. The storage directory $STORAGE (ROOT)"
for d in uploads imports exports reports; do run install -d -o www-data -g www-data -m 750 "$STORAGE/$d"; done
run install -d -o www-data -g www-data -m 750 "$STORAGE"

# ------------------------------------------------------------------------------------------------------------------
# 3. THE EDGAR USER-AGENT (the admin, right after apply; the owner's answer (b)). The SEC requires "Company Name contact@domain" on
#    every request and answers 403 "Undeclared Automated Tool" without it. It is a SETTING, not an env key: /admin/settings. No EDGAR
#    pull runs while either is empty (K35 asks the installer to warn).
echo "== 3. The EDGAR user-agent and the market holidays (the admin, at /admin/settings)"
echo "   Business name and EDGAR contact email; the exchange's holidays for the year (2026-11-26, 2026-12-25 …)"

# ------------------------------------------------------------------------------------------------------------------
# 4. The owner's keys and decisions (any time after 2):
echo "== 4. The owner's"
echo "   - DNS and TLS for shareholders.subello.com (until then /etc/hosts maps it to this host)"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME into $APP/config/.env (the morning note and the notifications to MEMBERS — nothing leaves the business) — the installer's mail step writes them from ~/.malumail when present"
echo "   - the Surveillance Analyst's duty (0 7 * * 1-5, America/New_York) in Agent HR if the hire gave none (the kernel reads agents[].duty since 2026-10-08)"
echo "   - the first issuer: /issuers/new (slice 1) — the ticker, the CIK lookup, the securities with their CUSIPs, the transfer agent; the restriction list for an advisory firm's client"
echo "   - the three files: this week's SPR, the latest NOBO job and the register export at /uploads/new (slices 2 and 3); the SPR parser was built from the reference grid and is CONFIRMED on the first real file"
echo "   - THE ALPHA VANTAGE KEY (the default price source since 2026-10-11, paused until it is set): /admin/sources → Alpha Vantage → Set the key (25 requests a day on the free plan); a licensed short-data key (Ortex, S3) is Extended"
echo "   - a PDF renderer for the weekly pack (optional): install wkhtmltopdf and set SI_WKHTMLTOPDF in config/.env — packs approved before it get their PDF from the stored HTML"
echo "   - the connections a super-admin approves (bin/app_connection.php): Knowledge reading filings_index and filing_text (a base over the filings), Spaces reading signals_open, events_index and ownership_summary"
echo "   - the worker, the directory sync and the ingest timers are rendered and enabled by apply; by hand:"
echo "       sudo systemctl enable --now shareholder_intelligence-worker.timer shareholder_intelligence-directory-sync.timer shareholder_intelligence-activity-ingest.timer"
