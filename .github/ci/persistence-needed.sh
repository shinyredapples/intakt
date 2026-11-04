#!/usr/bin/env bash
# Prints yes when the change from <base> to <head> touches what the persistence engines test, else no (docs/architecture/README.md).
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "usage: persistence-needed.sh <base> <head>" >&2
  exit 2
fi
base=$1
head=$2

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# Saved first under set -e, so a failing git can never read as "untouched".
git diff --name-only "$base" "$head" > "$work/changed-paths"
git diff "$base" "$head" -- src > "$work/src.diff"
git diff "$base" "$head" -- tests > "$work/tests.diff"

# Exit 1 means no match, an answer; above 1 is a failure.
status=0
git grep -l "Group('persistence')" "$head" -- tests > "$work/head-tests.raw" || status=$?
if [ "$status" -gt 1 ]; then
  exit "$status"
fi
cut -d: -f2- "$work/head-tests.raw" > "$work/head-tests"

touched=no
if grep -E '^(src/Core/Ledger/|src/Core/Platform/|tests/Core/Platform/|migrations/|config/packages/doctrine|composer\.(json|lock)$|phpunit\.dist\.xml$|tests/Application/MigrationsMatchMappingTest\.php$|tests/Core/Ledger/|\.github/(workflows|ci)/)' "$work/changed-paths" > /dev/null; then touched=yes
elif grep -E '^\+.*(Doctrine|ORM\\)' "$work/src.diff" > /dev/null; then touched=yes
elif grep -E "^[+-].*Group\('persistence'\)" "$work/tests.diff" > /dev/null; then touched=yes
elif grep -Fxf "$work/head-tests" "$work/changed-paths" > /dev/null; then touched=yes
fi
echo "$touched"
