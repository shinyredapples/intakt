#!/bin/bash
# Publishes the CI image to GitHub's container registry as ghcr.io/<repository>-ci:<hash>, the hash that names it in
# .github/ci/build-image.sh, so a build loop can run the checks in exactly CI's environment (docs/architecture/ci.md).
# Run by .github/workflows/ci-image.yml on main only. An image already published for the hash is left as it is; only a
# confirmed missing image is built and pushed, any other registry error fails the run.
# Environment: REPOSITORY (owner/name), ACTOR and GHCR_TOKEN (the job's token, packages: write).
set -euo pipefail

hash=$(.github/ci/build-image.sh --print-hash)
image=ghcr.io/${REPOSITORY,,}-ci:$hash

printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username "$ACTOR" --password-stdin
if answer=$(docker buildx imagetools inspect "$image" 2>&1 > /dev/null); then
    echo "$image is published already."
    exit 0
fi
if ! grep -qx -F "ERROR: $image: not found" <<< "$answer" && ! grep -qi 'manifest unknown' <<< "$answer"; then
    echo "Could not read $image: $answer" >&2
    exit 1
fi

# build-image.sh hands the tag to later steps through GITHUB_ENV; here the tag is known, so that line goes nowhere.
GITHUB_ENV=/dev/null .github/ci/build-image.sh
docker tag "intakt-ci:$hash" "$image"
docker push "$image"
echo "Pushed $image."
