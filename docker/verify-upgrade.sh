#!/bin/bash
set -euo pipefail

# A new version must start on data the version before it wrote, and going back must work the way docs/guide/your-data.md#upgrading tells users:
# the base's image on the untouched original, never on data the head migrated, because migrations only go forward.
# The head's image is the caller's (CI builds it once per run); the base is a commit, the pull request's merge base or the commit before a push to main.
if [[ $# -ne 2 || -z $1 || -z $2 ]]; then
    echo 'usage: docker/verify-upgrade.sh <head image> <base commit>' >&2
    exit 2
fi
head_image=$1
base_commit=$2
base_image=intakt:upgrade-base
original=intakt-upgrade-check-original
copy=intakt-upgrade-check-copy
name=intakt-upgrade-check
work=$(mktemp -d)

source "$(dirname "$0")/verify-first-start.sh"

cleanup() {
    docker rm --force "$name" >/dev/null 2>&1 || true
    docker volume rm "$original" "$copy" >/dev/null 2>&1 || true
}
trap 'cleanup; rm -rf "$work"' EXIT
cleanup

# The HEALTHCHECK is what an owner's Docker reports, so a start only counts once Docker calls the container healthy.
wait_until_healthy() {
    for _ in {1..60}; do
        [[ $(docker inspect --format '{{.State.Health.Status}}' "$name") == healthy ]] && return 0
        sleep 5
    done
    docker logs "$name" >&2
    echo "$name did not become healthy" >&2
    return 1
}

start_on() {
    start_demo "$name" "$1" "$2"
    wait_until_up "$name"
    wait_until_healthy
}

# What the owner set up must read the same after an upgrade: the seeded project's panel, the project file and the MCP token clients hold.
read_facts() {
    show_panel "$name"
    docker exec "$name" cat /app/var/data/intakt.yaml /app/var/data/mcp-owner-token
}

stop() {
    docker stop "$name" >/dev/null
    docker rm "$name" >/dev/null
}

same_facts() {
    read_facts > "$work/$1"
    diff "$work/base" "$work/$1" || {
        docker logs "$name" >&2
        echo "the $1 start reads other facts than the base wrote" >&2
        return 1
    }
}

echo "== build the base image from $base_commit"
git archive "$base_commit" | docker build --tag "$base_image" -

echo "== the base starts on a fresh volume and seeds it"
create_volume "$original" "$base_image"
start_on "$original" "$base_image"
read_facts > "$work/base"
stop

echo "== the head starts on a copy: migrations run, the facts read the same"
docker volume create "$copy" >/dev/null
docker run --rm --user root --entrypoint cp --volume "$original:/from:ro" --volume "$copy:/to" "$base_image" -a /from/. /to/
start_on "$copy" "$head_image"
docker exec "$name" php bin/console doctrine:migrations:up-to-date
same_facts upgrade
stop

echo "== rollback: the base starts again on the untouched original"
start_on "$original" "$base_image"
same_facts rollback
stop
