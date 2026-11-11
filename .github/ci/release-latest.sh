#!/bin/bash
# Points ghcr.io/<repository>:latest at the image of the highest release tag (docs/releasing.md). Run by the `latest`
# job of .github/workflows/release-image.yml, one run at a time across all tags. It reads the tags as they are now and
# never the tag of its own run, so runs can finish in any order, and one GitHub drops while it waits changes nothing:
# the run that goes ahead sets the same latest. A highest tag whose image is not pushed yet is left to its own run.
# Environment: REPOSITORY (owner/name), ACTOR, GHCR_TOKEN (packages: write), GH_TOKEN (reads the tags).
set -euo pipefail

release='^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'
image=ghcr.io/${REPOSITORY,,}
# sort -V orders numeric fields as numbers, so v0.10.0 comes after v0.9.0; only exact release tags take part.
highest=$(gh api --paginate "repos/$REPOSITORY/tags" --jq '.[].name' | { grep -E "$release" || true; } | sort -V | tail -n 1)
if [ -z "$highest" ]; then
    echo "No release tag: latest stays."
    exit 0
fi

printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username "$ACTOR" --password-stdin
if ! docker buildx imagetools inspect "$image:${highest#v}" > /dev/null 2>&1; then
    echo "$image:${highest#v} is not pushed yet: latest stays until the run of $highest."
    exit 0
fi
docker buildx imagetools create --tag "$image:latest" "$image:${highest#v}"
echo "$image:latest is ${highest#v}."
