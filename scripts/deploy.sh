#!/bin/bash
#
# Updates the API on a server over SSH: git pull, composer install, migrations (asked) and a fresh
# config cache.
#
#   composer run deploy            pick the environment from a menu
#   composer run deploy -- sta     or pass it (dev, sta, pro)
#   DRY_RUN=1 composer run deploy  print what would run, change nothing
#
# Settings come from .env (git-ignored). SSH_DIR is a template: {env} becomes dev, sta or pro (and {.env}
# becomes .dev, .sta or nothing in production). Any setting can be overridden for one environment with a
# prefix (STA_SSH_DIR, PRO_BRANCH, DEV_SSH_HOST, ...).
#
#   SSH_HOST=...   SSH_USER=...
#   SSH_DIR=/var/www/expenses.armn.do/{env}/api     (the server's clone of the repo)
#   PHP_BIN=php   COMPOSER_BIN=composer             (optional, for servers where they are elsewhere)
#   <ENV>_BRANCH=...                                (optional, defaults: develop, staging, master)

set -eu

cd "$(dirname "$0")/.."

ENVIRONMENTS="dev sta pro"

# Value of KEY in .env (last one wins, quotes and CR removed), empty when missing.
read_var() {
  [ -f .env ] || return 0
  { grep -E "^$1=" .env || true; } | tail -1 | cut -d= -f2- | sed -e 's/\r$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

# Runs the command, or only prints it in a dry run.
run() {
  if [ "${DRY_RUN:-0}" = "1" ]; then
    echo "[dry-run] $*"
  else
    "$@"
  fi
}

# Value of KEY for the chosen environment. ${PREFIX}_KEY is used as it is; otherwise KEY is a template
# where {env} becomes dev, sta or pro and {.env} becomes .dev, .sta or nothing in production. A KEY with
# neither mark is refused, so an environment can never silently get another one's value.
resolve() {
  local value dot
  value=$(read_var "${PREFIX}_$1")
  if [ -n "$value" ]; then
    echo "$value"
    return 0
  fi
  value=$(read_var "$1")
  [ -n "$value" ] || return 0
  case "$value" in
    *"{env}"*|*"{.env}"*) ;;
    *) echo "$1 in .env has no {env} or {.env}: make it a template or set ${PREFIX}_$1" >&2; exit 1 ;;
  esac
  dot=".$ENV_NAME"
  [ "$ENV_NAME" = "pro" ] && dot=""
  echo "$value" | sed -e "s/{\.env}/$dot/g" -e "s/{env}/$ENV_NAME/g"
}

# y/n question with a default; returns 0 for yes.
ask() {
  local answer
  read -r -p "$1 " answer
  answer=$(echo "${answer:-$2}" | tr '[:upper:]' '[:lower:]')
  case "$answer" in
    y|yes) return 0 ;;
    *) return 1 ;;
  esac
}

ENV_NAME=$(echo "${1:-}" | tr '[:upper:]' '[:lower:]')

if [ -z "$ENV_NAME" ]; then
  echo "Environment:"
  i=1
  for e in $ENVIRONMENTS; do
    echo "  $i) $e"
    i=$((i + 1))
  done
  echo ""
  read -r -p "Select the environment (name or number): " ENV_NAME
  ENV_NAME=$(echo "$ENV_NAME" | tr '[:upper:]' '[:lower:]')
  i=1
  for e in $ENVIRONMENTS; do
    [ "$ENV_NAME" = "$i" ] && ENV_NAME=$e
    i=$((i + 1))
  done
fi

case " $ENVIRONMENTS " in
  *" $ENV_NAME "*) ;;
  *) echo "Unknown environment '$ENV_NAME', use one of: $ENVIRONMENTS"; exit 1 ;;
esac

PREFIX=$(echo "$ENV_NAME" | tr '[:lower:]' '[:upper:]')

SSH_HOST=$(read_var "${PREFIX}_SSH_HOST")
[ -n "$SSH_HOST" ] || SSH_HOST=$(read_var SSH_HOST)
SSH_USER=$(read_var "${PREFIX}_SSH_USER")
[ -n "$SSH_USER" ] || SSH_USER=$(read_var SSH_USER)
SSH_DIR=$(resolve SSH_DIR)
BRANCH=$(read_var "${PREFIX}_BRANCH")
if [ -z "$BRANCH" ]; then
  case "$ENV_NAME" in
    dev) BRANCH=develop ;;
    sta) BRANCH=staging ;;
    pro) BRANCH=master ;;
  esac
fi
PHP_BIN=$(read_var PHP_BIN)
[ -n "$PHP_BIN" ] || PHP_BIN=php
COMPOSER_BIN=$(read_var COMPOSER_BIN)
[ -n "$COMPOSER_BIN" ] || COMPOSER_BIN=composer

missing=""
[ -n "$SSH_HOST" ] || missing="$missing SSH_HOST"
[ -n "$SSH_USER" ] || missing="$missing SSH_USER"
[ -n "$SSH_DIR" ] || missing="$missing SSH_DIR"

if [ -n "$missing" ]; then
  echo "Not set in .env:$missing"
  exit 1
fi

case "$SSH_DIR" in
  "/"|"~"|"~/"|*" "*) echo "Refusing to deploy to SSH_DIR='$SSH_DIR'"; exit 1 ;;
esac

# What the server is going to get: the tip of the branch on origin, which is what it pulls.
git fetch -q origin "$BRANCH" 2>/dev/null || { echo "Branch '$BRANCH' is not on origin."; exit 1; }
tip=$(git log -1 --format='%h %s (%cd)' --date=short FETCH_HEAD)
release=$(git describe --tags --abbrev=0 FETCH_HEAD 2>/dev/null || echo "no tag")

echo ""
echo "Environment: $ENV_NAME"
echo "   SSH_HOST = '$SSH_HOST'"
echo "   SSH_USER = '$SSH_USER'"
echo "    SSH_DIR = '$SSH_DIR'"
echo "     BRANCH = '$BRANCH'"
echo "     COMMIT = $tip"
echo " LATEST TAG = $release"
echo ""

if [ "$ENV_NAME" = "pro" ]; then
  read -r -p "This is PRODUCTION. Type 'pro' to deploy: " response
  [ "$response" = "pro" ] || { echo "Aborting."; exit 1; }
else
  ask "Do you want to deploy with these settings? (y)/n:" y || { echo "Aborting."; exit 1; }
fi

target="$SSH_USER@$SSH_HOST"

echo ""
echo "Updating the code..."
run ssh "$target" "cd $SSH_DIR && git fetch --prune origin && git checkout $BRANCH && git pull --ff-only origin $BRANCH"

echo ""
echo "Installing dependencies..."
run ssh "$target" "cd $SSH_DIR && $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction"

echo ""
echo "Migrations pending on the server:"
run ssh "$target" "cd $SSH_DIR && $PHP_BIN artisan migrate:status --pending"
echo ""
if [ "$ENV_NAME" = "pro" ]; then
  echo "Back up the production database before migrating."
  default=n
else
  default=y
fi
if ask "Run the migrations now? ($default):" "$default"; then
  run ssh "$target" "cd $SSH_DIR && $PHP_BIN artisan migrate --force"
else
  echo "Skipping the migrations, run them by hand when you are ready."
fi

echo ""
echo "Refreshing the config cache..."
run ssh "$target" "cd $SSH_DIR && $PHP_BIN artisan config:clear && $PHP_BIN artisan config:cache"

echo ""
echo "Done deploying to $ENV_NAME ($BRANCH, $tip)."
