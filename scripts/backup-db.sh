#!/usr/bin/env bash
#
# Backs up a PostgreSQL database to a file (e.g. pro before a deploy with migrations). It only reads.
#
#   scripts/backup-db.sh <profile> [label]
#   composer run backup-db -- pro 0.9.0     -> dumps/pro_backup_0.9.0_<stamp>.sql
#
# <profile> is a profile name defined in scripts/copy-db.env (see copy-db.env.example), the same ones copy-db.sh
# uses, so for pro from a laptop open the SSH tunnel first (ssh -N -L 5433:127.0.0.1:5432 user@server).
# The optional label goes in the file name (letters, digits, dot, dash and underscore).
#
# Safety:
#   1. The database is only read (pg_dump), never written: any profile is allowed, "pro" included.
#   2. The dump is written to a .partial file and renamed only when pg_dump finished and the file ends as a
#      complete dump, so a failed backup never leaves a file that looks good.
#   3. The file is readable by you only (it holds real data) and lives in dumps/, which is git-ignored.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${COPY_DB_CONFIG:-$ROOT/scripts/copy-db.env}"
DUMP_DIR="${DUMP_DIR:-$ROOT/dumps}"

usage() {
  echo "Usage: $0 <profile> [label]" >&2
  echo "Profiles come from $CONFIG (see scripts/copy-db.env.example)." >&2
  exit 1
}

die() { echo "error: $*" >&2; exit 1; }

[[ $# -ge 1 && $# -le 2 ]] || usage
PROFILE="$1"; LABEL="${2:-}"

[[ -z "$LABEL" || "$LABEL" =~ ^[A-Za-z0-9._-]+$ ]] || die "the label can only have letters, digits, '.', '-' and '_'"
[[ -f "$CONFIG" ]] || die "config not found: $CONFIG"
# PG_BIN: directory of the PostgreSQL client tools to use (pg_dump must be at least the server's version).
PG_DUMP="${PG_BIN:+$PG_BIN/}pg_dump"
command -v "$PG_DUMP" >/dev/null || die "pg_dump is required"

# shellcheck disable=SC1090
source "$CONFIG"

# profile <name> <field> -> value of <NAME>_<FIELD> (HOST, PORT, USER, DB, PASSWORD)
profile() {
  local var; var="$(echo "$1" | tr '[:lower:]-' '[:upper:]_')_$2"
  echo "${!var:-}"
}

for f in HOST USER DB; do
  [[ -n "$(profile "$PROFILE" "$f")" ]] || die "missing $(echo "$PROFILE" | tr '[:lower:]-' '[:upper:]_')_$f in $CONFIG"
done

HOST="$(profile "$PROFILE" HOST)"
PORT="$(profile "$PROFILE" PORT)"; PORT="${PORT:-5432}"
DB_USER="$(profile "$PROFILE" USER)"
DB="$(profile "$PROFILE" DB)"

umask 077
mkdir -p "$DUMP_DIR"
STAMP="$(date +%Y-%m-%d_%H%M%S)"
FILE="$DUMP_DIR/${PROFILE}_backup_${LABEL:+${LABEL}_}${STAMP}.sql"
TMP="$FILE.partial"

echo "Backing up '$PROFILE' ($DB_USER@$HOST:$PORT/$DB) -> $FILE"

PGPASSWORD="$(profile "$PROFILE" PASSWORD)" "$PG_DUMP" -h "$HOST" -p "$PORT" -U "$DB_USER" -d "$DB" \
  --clean --if-exists --no-owner --no-privileges -f "$TMP" || { rm -f "$TMP"; die "pg_dump of '$PROFILE' failed"; }
grep -q "PostgreSQL database dump complete" "$TMP" || { rm -f "$TMP"; die "the dump of '$PROFILE' looks incomplete"; }
mv "$TMP" "$FILE"

TABLES="$(grep -c '^COPY public\.' "$FILE" || true)"
SIZE="$(du -h "$FILE" | cut -f1)"
echo "Done: $SIZE, $TABLES tables with data, ends as a complete dump."
echo "To restore (replaces the data of the target, be sure of the profile):"
echo "  psql -h <host> -p <port> -U <user> -d <db> --single-transaction -v ON_ERROR_STOP=1 -f $FILE"
