#!/bin/bash
# Builds the app image of a release tag, smoke-tests it and pushes it to GitHub's container registry as
# ghcr.io/<repository>:<version>, and as :latest only when the tag is the highest release tag (docs/releasing.md).
# Run by .github/workflows/release-image.yml from the workflow's own revision, so a tag cut before this script existed
# can still be published: the tag is checked out apart, in CONTEXT, and only built from there.
# Environment: TAG (vX.Y.Z), CONTEXT (the tag's checkout, with its tags fetched), REPOSITORY (owner/name), ACTOR and
# GHCR_TOKEN (the job's token, packages: write).
set -euo pipefail

release='^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'
if ! [[ ${TAG:-} =~ $release ]]; then
    echo "'${TAG:-}' is not a release tag (vX.Y.Z): nothing is built." >&2
    exit 1
fi
revision=$(git -C "$CONTEXT" rev-parse HEAD)
if [ "$(git -C "$CONTEXT" rev-parse "refs/tags/$TAG^{commit}" 2>/dev/null)" != "$revision" ]; then
    echo "The checkout in $CONTEXT is not the commit of $TAG: nothing is built." >&2
    exit 1
fi
version=${TAG#v}
image=ghcr.io/${REPOSITORY,,}
# sort -V orders numeric fields as numbers, so v0.10.0 comes after v0.9.0; only exact release tags take part.
highest=$(git -C "$CONTEXT" tag --list 'v*' | grep -E "$release" | sort -V | tail -n 1)
names=("$version")
if [ "$TAG" = "$highest" ]; then
    names+=(latest)
fi

# The source label links the package to the repository; a new package is still private until its visibility is
# changed once in the package settings (docs/releasing.md).
docker build \
    --label "org.opencontainers.image.source=https://github.com/$REPOSITORY" \
    --label "org.opencontainers.image.version=$version" \
    --label "org.opencontainers.image.revision=$revision" \
    --tag intakt:release "$CONTEXT"
docker/smoke-test.sh intakt:release

printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username "$ACTOR" --password-stdin
for name in "${names[@]}"; do
    docker tag intakt:release "$image:$name"
    docker push "$image:$name"
done
echo "Pushed $image as ${names[*]} ($revision)."
