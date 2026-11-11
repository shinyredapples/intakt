#!/bin/bash
# Builds the app image of a release tag from the checkout, smoke-tests it and pushes it to GitHub's container registry as
# ghcr.io/<repository>:<version> and :latest (docs/releasing.md). Run by .github/workflows/release-image.yml.
# Environment: TAG (vX.Y.Z), REPOSITORY (owner/name), ACTOR and GHCR_TOKEN (the job's token, packages: write).
set -euo pipefail

if ! [[ ${TAG:-} =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
    echo "'${TAG:-}' is not a release tag (vX.Y.Z): nothing is built." >&2
    exit 1
fi
version=${TAG#v}
image=ghcr.io/${REPOSITORY,,}
revision=$(git rev-parse HEAD)

# The source label links the package to the repository, so it takes the repository's visibility and access.
docker build \
    --label "org.opencontainers.image.source=https://github.com/$REPOSITORY" \
    --label "org.opencontainers.image.version=$version" \
    --label "org.opencontainers.image.revision=$revision" \
    --tag intakt:release .
docker/smoke-test.sh intakt:release

printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username "$ACTOR" --password-stdin
for name in "$version" latest; do
    docker tag intakt:release "$image:$name"
    docker push "$image:$name"
done
echo "Pushed $image:$version ($revision) and $image:latest."
