#!/usr/bin/env bash
# Prints yes when the change from <base> to <head> needs the app image built and checked, else no.
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "usage: image-needed.sh <base> <head>" >&2
  exit 2
fi
base=$1
head=$2

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# Saved first under set -e, so a failing git can never read as "not needed".
# --no-renames lists a renamed file under its old path too, so moving a file into the build still counts.
git diff --no-renames --name-only "$base" "$head" > "$work/changed-paths"

# Only these folders are left out of the image by .dockerignore; an empty change proves nothing, so it builds.
needed=yes
if [ -s "$work/changed-paths" ] && ! grep -Ev '^(docs|tests|upstream)/' "$work/changed-paths" > /dev/null; then
  needed=no
fi
echo "$needed"
