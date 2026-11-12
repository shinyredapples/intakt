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
# Only a confirmed missing image waits for its own run; any other failure (an outage, a refused token) fails this run,
# so it shows red and can be run again, instead of leaving latest behind on a green run.
if ! answer=$(docker buildx imagetools inspect "$image:${highest#v}" 2>&1 > /dev/null); then
    # Missing means buildx's own line for this exact reference, or the registry's MANIFEST_UNKNOWN; a 404 from anything
    # else (the token endpoint, a proxy) is an error like any other.
    if grep -qx -F "ERROR: $image:${highest#v}: not found" <<< "$answer" || grep -qi 'manifest unknown' <<< "$answer"; then
        echo "$image:${highest#v} is not pushed yet: latest stays until the run of $highest."
        exit 0
    fi
    echo "Could not read $image:${highest#v}: $answer" >&2
    exit 1
fi
docker buildx imagetools create --tag "$image:latest" "$image:${highest#v}"
echo "$image:latest is ${highest#v}."
