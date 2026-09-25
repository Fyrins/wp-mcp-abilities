#!/usr/bin/env bash
# Syncs the publishable package into the testbed, then runs each smoke script.
# Usage: bin/smoke.sh [script-name ...]   (defaults to every tests/smoke/*.php)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TESTBED="${WPMCPA_TESTBED:-$HOME/Sites/wp-mcp-abilities-testbed}"
cd "$TESTBED"
./sync-plugin.sh >/dev/null
rm -rf smoke && cp -R "$ROOT/tests/smoke" smoke
: > wp-content/debug.log
SCRIPTS=("$@")
[ ${#SCRIPTS[@]} -gt 0 ] || SCRIPTS=($(cd smoke && ls *.php | grep -v '^assert.php$'))
STATUS=0
for s in "${SCRIPTS[@]}"; do
  echo "== ${s%.php}"
  ddev wp eval-file "smoke/${s%.php}.php" || STATUS=1
done
if [ -s wp-content/debug.log ]; then
  echo "== debug.log is not empty:"; cat wp-content/debug.log; STATUS=1
fi
exit $STATUS
