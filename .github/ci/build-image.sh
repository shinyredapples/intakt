#!/bin/sh
# Builds the CI image from .github/ci/Dockerfile unless an image of those exact inputs is already there, and hands its
# tag to the later steps as CI_IMAGE. The tag hashes the Dockerfile and every file it copies, so it names its content.
# Reusing an existing tag is safe only because a self-hosted runner must be ephemeral: each job starts from a clean
# machine state (a fresh VM or container, or a snapshot rolled back before the job, the runner registered with
# --ephemeral for one job). A pull request's job runs this script, and anything it likes, with Docker access on the host,
# so on a persistent runner it could tag another image with main's hash for every later job, main's included. On a
# clean state a tag that exists at job start can only come from the image the owner prepared. A persistent self-hosted
# runner is not supported.
set -eu
image="intakt-ci:$(sha256sum .github/ci/Dockerfile composer.json config/gates/php/check.ini | sha256sum | cut -c1-32)"
if ! docker image inspect "$image" > /dev/null 2>&1; then
    docker build --tag "$image" --file .github/ci/Dockerfile .
fi
echo "CI_IMAGE=$image" >> "$GITHUB_ENV"
