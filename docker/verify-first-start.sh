#!/bin/bash
set -euo pipefail

# End to end proof of the demo's first start: the real image on an empty volume, then the review command the first start prints.
# No model is called, because intakt:review:panel only shows the panel; the key is a placeholder that lets the seed run.
image=intakt-first-start-check
volume=intakt-first-start-check-data
name=intakt-first-start-check

cleanup() {
    docker rm --force "$name" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup

docker build --tag "$image" .
docker volume create "$volume" >/dev/null
# A fresh volume is owned by root, and the image runs as one fixed user.
docker run --rm --user root --entrypoint chown --volume "$volume:/app/var/data" "$image" 10001:10001 /app/var/data

docker run --detach --name "$name" \
    --env APP_SECRET="$(openssl rand -hex 32)" \
    --env MERCURE_JWT_SECRET="$(openssl rand -hex 32)" \
    --env ANTHROPIC_API_KEY=unused \
    --env INTAKT_CONFIG=/app/var/data/intakt.yaml \
    --env INTAKT_SAMPLE_REPOSITORY=/app/var/data/sample-repository \
    --volume "$volume:/app/var/data" \
    "$image" >/dev/null

# The server's admin API answers only once the entrypoint is past the seed and the last rebuild of the container.
for _ in {1..120}; do
    docker exec "$name" curl --fail --silent --output /dev/null http://localhost:2019/metrics && break
    sleep 2
done

docker exec "$name" curl --fail --silent --output /dev/null http://localhost:2019/metrics || {
    docker logs "$name" >&2
    echo 'the first start did not come up' >&2
    exit 1
}

docker exec "$name" php bin/console intakt:review:panel sample || {
    docker logs "$name" >&2
    echo 'the running app does not see the seeded panel' >&2
    exit 1
}
