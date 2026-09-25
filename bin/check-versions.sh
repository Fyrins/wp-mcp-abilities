#!/usr/bin/env bash
#
# Checks that the version number is the same everywhere.
#
# It lives in four places: the main file header, the PHP constant, the
# readme.txt Stable tag, and the git tag. A mismatch publishes a package
# served under the wrong number, which only a new release can fix. Hence a
# blocking check BEFORE any access to SVN.
#
# Usage: check-versions.sh [plugin-path] [git-tag]
# Without a tag, the first three are compared with one another.

set -euo pipefail

SRC="$(cd "${1:-$PWD}" && pwd)"
TAG="${2:-}"
cd "$SRC"

MAIN=""
for f in *.php; do
  [ -f "$f" ] || continue
  if grep -q '^\s*\*\s*Plugin Name:' "$f"; then MAIN="$f"; break; fi
done
[ -n "$MAIN" ] || { echo "Main plugin file not found in $SRC." >&2; exit 1; }

HEADER="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' "$MAIN" | head -n1 | tr -d '[:space:]')"
CONST="$(sed -n "s/.*_VERSION'[^']*'\([^']*\)'.*/\1/p" "$MAIN" | head -n1)"
STABLE=""
[ -f readme.txt ] && STABLE="$(sed -n 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p' readme.txt | head -n1 | tr -d '[:space:]')"

printf 'Plugin header     : %s\n' "${HEADER:-(missing)}"
printf 'PHP constant      : %s\n' "${CONST:-(missing)}"
printf 'Readme stable tag : %s\n' "${STABLE:-(missing)}"
[ -n "$TAG" ] && printf 'Git tag           : %s\n' "${TAG#v}"

REF="${TAG#v}"
[ -n "$REF" ] || REF="$HEADER"

FAILED=0
for pair in "header:$HEADER" "constant:$CONST" "stable tag:$STABLE"; do
  label="${pair%%:*}"; value="${pair#*:}"
  [ -n "$value" ] || continue
  if [ "$value" != "$REF" ]; then
    echo "Mismatch: $label is '$value', expected '$REF'." >&2
    FAILED=1
  fi
done

if [ "$STABLE" = "trunk" ]; then
  echo "Stable tag is 'trunk': not allowed for a new plugin on wordpress.org." >&2
  FAILED=1
fi

# The changelog section is checked here, before anything is published. By the
# time the release is created, the package is already live, and a missing
# section could only be fixed by shipping a new version.
if [ -f CHANGELOG.md ] && ! grep -qF "## [$REF]" CHANGELOG.md; then
  echo "CHANGELOG.md has no '## [$REF]' section." >&2
  FAILED=1
fi

[ "$FAILED" -eq 0 ] || exit 1
echo "All version numbers match $REF."
