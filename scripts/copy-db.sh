#!/usr/bin/env bash
#
# Copies the data of one PostgreSQL database over another one (e.g. pro -> dev).
#
#   scripts/copy-db.sh <from> <to> [--yes]
#
# <from> and <to> are profile names defined in scripts/copy-db.env (see copy-db.env.example), so the same
# script works from the server (both databases on the same host) or from a laptop (remote pro -> local dev).
#
# Safety:
#   1. The target is dumped first to dumps/<to>_before_<stamp>.sql, so a copy can be undone.
#   2. The source is dumped to dumps/<from>_<stamp>.sql.
#   3. The source dump is loaded in ONE transaction with ON_ERROR_STOP: any error rolls everything back
#      and the target stays as it was.
#   4. It never writes to the "pro" profile and asks for confirmation (type the target profile name).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${COPY_DB_CONFIG:-$ROOT/scripts/copy-db.env}"
DUMP_DIR="${DUMP_DIR:-$ROOT/dumps}"
PROTECTED="pro"

usage() {
  echo "Usage: $0 <from> <to> [--yes]" >&2
  echo "Profiles come from $CONFIG (see scripts/copy-db.env.example)." >&2
  exit 1
}

die() { echo "error: $*" >&2; exit 1; }

[[ $# -ge 2 ]] || usage
FROM="$1"; TO="$2"; ASSUME_YES="no"
[[ "${3:-}" == "--yes" ]] && ASSUME_YES="yes"

[[ "$FROM" != "$TO" ]] || die "source and target are the same profile"
[[ "$TO" != "$PROTECTED" ]] || die "refusing to overwrite the '$PROTECTED' profile"
[[ -f "$CONFIG" ]] || die "config not found: $CONFIG"
# PG_BIN: directory of the PostgreSQL client tools to use (pg_dump must be at least the server's version,
# e.g. PG_BIN=/opt/homebrew/opt/postgresql@16/bin). Defaults to the ones in PATH.
PG_DUMP="${PG_BIN:+$PG_BIN/}pg_dump"
PSQL="${PG_BIN:+$PG_BIN/}psql"
command -v "$PG_DUMP" >/dev/null && command -v "$PSQL" >/dev/null || die "pg_dump and psql are required"

# shellcheck disable=SC1090
source "$CONFIG"

# profile <name> <field> -> value of <NAME>_<FIELD> (HOST, PORT, USER, DB, PASSWORD)
profile() {
  local var; var="$(echo "$1" | tr '[:lower:]-' '[:upper:]_')_$2"
  echo "${!var:-}"
}

for p in "$FROM" "$TO"; do
  for f in HOST USER DB; do
    [[ -n "$(profile "$p" "$f")" ]] || die "missing $(echo "$p" | tr '[:lower:]-' '[:upper:]_')_$f in $CONFIG"
  done
done

# Never load into a test database, nor into one that is not named in the profile.
[[ "$(profile "$TO" DB)" != *_test ]] || die "refusing to overwrite a *_test database"

conn_args() {
  echo "-h $(profile "$1" HOST) -p $(profile "$1" PORT || true) -U $(profile "$1" USER) -d $(profile "$1" DB)" \
    | sed "s/-p  /-p 5432 /"
}

run_dump() { # <profile> <file>
  local p="$1" file="$2" tmp="$2.partial"
  # shellcheck disable=SC2046
  PGPASSWORD="$(profile "$p" PASSWORD)" "$PG_DUMP" $(conn_args "$p") \
    --clean --if-exists --no-owner --no-privileges -f "$tmp" || { rm -f "$tmp"; die "pg_dump of '$p' failed"; }
  grep -q "PostgreSQL database dump complete" "$tmp" || { rm -f "$tmp"; die "dump of '$p' looks incomplete"; }
  mv "$tmp" "$file"
}

STAMP="$(date +%Y-%m-%d_%H%M%S)"
mkdir -p "$DUMP_DIR"
BACKUP="$DUMP_DIR/${TO}_before_$STAMP.sql"
SNAPSHOT="$DUMP_DIR/${FROM}_$STAMP.sql"

describe() { # <profile> -> user@host:port/db
  echo "$(profile "$1" USER)@$(profile "$1" HOST):$(profile "$1" PORT | grep . || echo 5432)/$(profile "$1" DB)"
}

echo "From: $FROM ($(describe "$FROM"))"
echo "To:   $TO ($(describe "$TO")) -- its data will be REPLACED"

if [[ "$ASSUME_YES" != "yes" ]]; then
  read -r -p "Type '$TO' to continue: " answer
  [[ "$answer" == "$TO" ]] || die "cancelled"
fi

echo "1/3 Backing up '$TO' -> $BACKUP"
run_dump "$TO" "$BACKUP"

echo "2/3 Dumping '$FROM' -> $SNAPSHOT"
run_dump "$FROM" "$SNAPSHOT"

echo "3/3 Loading into '$TO' (single transaction)"
# shellcheck disable=SC2046
if PGPASSWORD="$(profile "$TO" PASSWORD)" "$PSQL" $(conn_args "$TO") \
  --single-transaction -v ON_ERROR_STOP=1 -q -f "$SNAPSHOT" >/dev/null; then
  echo "Done. '$TO' now has the data of '$FROM'."
  echo "Run 'php artisan migrate' if '$TO' has migrations that '$FROM' lacks."
  echo "To undo: $PSQL $(conn_args "$TO") --single-transaction -v ON_ERROR_STOP=1 -f $BACKUP"
else
  echo "Load failed and was rolled back: '$TO' is unchanged." >&2
  echo "The dumps are kept in $DUMP_DIR." >&2
  exit 1
fi
