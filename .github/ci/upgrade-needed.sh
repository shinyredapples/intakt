#!/usr/bin/env bash
# Prints yes when the change from <base> to <head> touches what decides whether a new version starts on the old one's data
# (docker/verify-upgrade.sh), else no: migrations, a Doctrine-mapped class, the image or the locked dependencies.
set -euo pipefail
# Bytes, not characters: in a UTF-8 locale read -d '' takes the NUL after a cut multibyte character into the path.
export LC_ALL=C

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
# -z gives each path raw and NUL-terminated: git otherwise quotes a path with a tab, a newline or a non-ASCII character,
# and the leading quote would hide its folder.
git diff -z --no-renames --name-only "$base" "$head" > "$work/changed-paths"

# A class is mapped when it carries a Doctrine attribute on either side, so adding and removing a mapping both count.
# Exit 1 means no match, an answer; above 1 is a failure.
status=0
git grep -z -l -F '#[ORM\' "$base" "$head" -- src > "$work/mapped.raw" || status=$?
if [ "$status" -gt 1 ]; then
  exit "$status"
fi

# Compared in bash, because grep -f splits its patterns on newlines even under -z, so a path with a newline would match
# its pieces. Each entry is <revision>:<path>, and a revision name holds no colon, so the path starts after the first.
declare -A mapped=()
while IFS= read -r -d '' entry; do
  mapped["${entry#*:}"]=1
done < "$work/mapped.raw"

touched=no
while IFS= read -r -d '' path; do
  case $path in
    migrations/* | Dockerfile | docker/* | composer.lock) touched=yes ;;
    *) if [ -n "${mapped[$path]+set}" ]; then touched=yes; fi ;;
  esac
  if [ "$touched" = yes ]; then break; fi
done < "$work/changed-paths"
echo "$touched"
