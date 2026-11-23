#!/bin/sh
# Logs the runner's Docker in to Docker Hub when the repository secrets DOCKERHUB_USERNAME and DOCKERHUB_TOKEN are set,
# so pulls count against that account's limit instead of the runner's IP address, which a hosted runner service shares
# with its other customers (Docker Hub allows few anonymous pulls per address). Without the token the pulls stay
# anonymous, as before. Use a read-only token: a same-repository pull request's job can read it.
set -eu
if [ -z "${DOCKERHUB_TOKEN:-}" ]; then
    echo "No Docker Hub token set: pulls stay anonymous."
    exit 0
fi
# A failed login does not fail the job: a CI image already on the runner or restored from the cache needs no pull, and
# .github/actions/ci-image fails only when it would have to pull or build after one. Other pulls go on anonymously.
if ! printf '%s' "$DOCKERHUB_TOKEN" | docker login --username "$DOCKERHUB_USERNAME" --password-stdin; then
    echo "::warning::Docker Hub login failed: pulls stay anonymous."
    echo "DOCKERHUB_LOGIN_FAILED=1" >> "$GITHUB_ENV"
fi
