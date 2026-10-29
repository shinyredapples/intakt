#!/bin/sh
# Builds the CI image from .github/ci/Dockerfile unless an image of that exact Dockerfile is already there (a self-hosted
# runner keeps it between jobs), and hands its tag to the later steps as CI_IMAGE. The Dockerfile is the whole build
# input (no context), so the tag names its content.
set -eu
image="intakt-ci:$(sha256sum .github/ci/Dockerfile | cut -c1-32)"
if ! docker image inspect "$image" > /dev/null 2>&1; then
    docker build --tag "$image" - < .github/ci/Dockerfile
fi
echo "CI_IMAGE=$image" >> "$GITHUB_ENV"
