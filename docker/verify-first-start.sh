#!/bin/bash
set -euo pipefail

# Smoke check of the demo's first start on the real image and an empty volume; a single run may pass even with a stale container.
# intakt:review:panel calls no model, so the key is a placeholder that only lets the seed run.
# The image is the caller's, so CI checks the one image it built for the smoke test instead of building a second.
# docker/verify-upgrade.sh sources the functions below, so the upgrade check seeds its volume exactly as this check does.

# A fresh volume is owned by root, and the image runs as one fixed user.
create_volume() {
    local volume=$1 image=$2
    docker volume create "$volume" >/dev/null
    docker run --rm --user root --entrypoint chown --volume "$volume:/app/var/data" "$image" 10001:10001 /app/var/data
}

start_demo() {
    local name=$1 volume=$2 image=$3
    docker run --detach --name "$name" \
        --env APP_SECRET="$(openssl rand -hex 32)" \
        --env MERCURE_JWT_SECRET="$(openssl rand -hex 32)" \
        --env ANTHROPIC_API_KEY=unused \
        --env INTAKT_CONFIG=/app/var/data/intakt.yaml \
        --env INTAKT_SAMPLE_REPOSITORY=/app/var/data/sample-repository \
        --volume "$volume:/app/var/data" \
        "$image" >/dev/null
}

# The server's admin API answers only once the entrypoint is past the migrations, the seed and the last rebuild of the container.
wait_until_up() {
    local name=$1
    for _ in {1..120}; do
        docker exec "$name" curl --fail --silent --output /dev/null http://localhost:2019/metrics && return 0
        sleep 2
    done
    docker exec "$name" curl --fail --silent --output /dev/null http://localhost:2019/metrics && return 0
    docker logs "$name" >&2
    echo "$name did not come up" >&2
    return 1
}

show_panel() {
    local name=$1
    docker exec "$name" php bin/console intakt:review:panel sample || {
        docker logs "$name" >&2
        echo "$name does not see the seeded panel" >&2
        return 1
    }
}

if [[ ${BASH_SOURCE[0]} != "$0" ]]; then
    return 0
fi

if [[ $# -ne 1 || -z $1 ]]; then
    echo 'usage: docker/verify-first-start.sh <image>' >&2
    exit 2
fi
image=$1
volume=intakt-first-start-check-data
name=intakt-first-start-check

cleanup() {
    docker rm --force "$name" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup

create_volume "$volume" "$image"
start_demo "$name" "$volume" "$image"
wait_until_up "$name"
show_panel "$name"
