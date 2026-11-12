#!/usr/bin/env bash
# Prints yes when the change from <base> to <head> touches what decides whether a new version starts on the old one's data
# (docker/verify-upgrade.sh), else no: migrations, a Doctrine-mapped class, the image or the locked dependencies.
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "usage: upgrade-needed.sh <base> <head>" >&2
  exit 2
fi
base=$1
head=$2

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# Saved first under set -e, so a failing git can never read as "untouched".
# --no-renames lists a renamed file under its old path too, so moving a mapped class away still counts.
git diff --no-renames --name-only "$base" "$head" > "$work/changed-paths"

# A class is mapped when it carries a Doctrine attribute on either side, so adding and removing a mapping both count.
# Exit 1 means no match, an answer; above 1 is a failure.
status=0
git grep -l -F '#[ORM\' "$base" "$head" -- src > "$work/mapped.raw" || status=$?
if [ "$status" -gt 1 ]; then
  exit "$status"
fi
cut -d: -f2- "$work/mapped.raw" > "$work/mapped"

touched=no
if grep -E '^(migrations/|Dockerfile$|docker/|composer\.lock$)' "$work/changed-paths" > /dev/null; then touched=yes
elif grep -Fxf "$work/mapped" "$work/changed-paths" > /dev/null; then touched=yes
fi
echo "$touched"
