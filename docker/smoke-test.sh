#!/bin/bash
set -euo pipefail

usage() {
    echo "usage: $0 IMAGE" >&2
    echo "       $0 --local ORIGIN ADMIN CLOSED_ADMIN" >&2
    echo "  --local runs the checks that need no Docker against a running FrankenPHP:" >&2
    echo "  ORIGIN serves the app, ADMIN is its admin API, CLOSED_ADMIN must not answer." >&2
    exit 2
}

step=arguments

begin() {
    step=$1
    echo "== $step"
}

fail() {
    echo "smoke test failed at step '$step': $1" >&2
    exit 1
}

# The admin API answers only where the server runs, so in the image it is read through docker exec.
in_server=()
# The dashboard refuses a Host that is not loopback on DASHBOARD_PORT (RefuseForeignHost), so the image is asked as localhost:7781 and curl connects to the published port.
reach=()

check_server() {
    check_dashboard "$1"
    check_mcp_worker "$1" "$2"
    check_admin_inside "$2"
    check_admin_closed "$3"
}

check_dashboard() {
    local answer status
    begin dashboard
    answer=$(curl --silent --show-error --max-time 10 --write-out '\n%{http_code}' "${reach[@]}" "$1/projects") || fail "GET /projects did not answer"
    status=${answer##*$'\n'}
    [[ $status == 200 ]] || fail "GET /projects answered $status, not 200"
    grep -q '<title>[^<]*Intakt</title>' <<<"$answer" || fail "GET /projects has no Intakt title"
    echo "GET /projects answered 200 with the Intakt title"
}

check_mcp_worker() {
    local before after
    begin mcp-worker
    before=$(mcp_requests "$2") || fail "the admin API on $2 lists no mcp.php worker thread"
    curl --silent --output /dev/null --max-time 10 --request POST "${reach[@]}" "$1/mcp/owner" || fail "POST /mcp/owner did not answer"
    # FrankenPHP counts a request only when the worker has finished it, after the answer is sent.
    for _ in {1..20}; do
        after=$(mcp_requests "$2") || fail "the admin API on $2 lists no mcp.php worker thread"
        if ((after > before)); then
            echo "POST /mcp/owner was served by the mcp worker (mcp.php requests: $before -> $after)"
            return
        fi
        sleep 0.5
    done
    fail "POST /mcp/owner did not reach the mcp worker (mcp.php requests stayed at $before)"
}

# The thread list is untrusted input: anything but a list with an mcp.php thread is a failure, never a count of 0.
mcp_requests() {
    "${in_server[@]}" php -r '
        $states = json_decode((string) @file_get_contents($argv[1]), true)["ThreadDebugStates"] ?? null;
        $mcp = array_filter(is_array($states) ? $states : [], fn ($s) => is_array($s) && str_ends_with((string) ($s["Name"] ?? ""), "/mcp.php"));
        if ([] === $mcp) { exit(1); }
        echo array_sum(array_map(fn ($s) => (int) ($s["RequestCount"] ?? 0), $mcp));
    ' "http://$1/frankenphp/threads"
}

check_admin_inside() {
    begin admin-inside
    "${in_server[@]}" curl --silent --fail --output /dev/null --max-time 5 "http://$1/config/" || fail "the admin API does not answer on $1 where the server runs"
    echo "the admin API answers on $1 where the server runs"
}

check_admin_closed() {
    begin admin-closed
    if curl --silent --output /dev/null --max-time 5 "http://$1/config/"; then
        fail "the admin API answers on $1 from outside"
    fi
    echo "nothing answers on $1 from outside"
}

smoke_image() {
    image=$1
    smoke=intakt-smoke-$$-$RANDOM
    web=$smoke-web
    volume=$smoke-data
    containers=()
    trap remove_everything EXIT

    print_digests
    start_container
    wait_until_migrated
    in_server=(docker exec "$web")
    check_server "$origin" localhost:2019 127.0.0.1:2019
    check_admin_unpublished
    check_user
    check_instance
    check_missing_secret APP_SECRET
    check_missing_secret MERCURE_JWT_SECRET
    echo "smoke test passed: $image"
}

remove_everything() {
    if ((${#containers[@]} > 0)); then
        docker rm -f "${containers[@]}" >/dev/null 2>&1 || true
    fi
    docker volume rm -f "$volume" >/dev/null 2>&1 || true
}

print_digests() {
    local digest tag base
    begin digests
    digest=$(docker image inspect --format '{{.Id}}' "$image") || fail "docker cannot inspect $image"
    echo "Image digest: $digest"
    tag=$(awk 'toupper($1) == "FROM" { print $2; exit }' "$(dirname "$0")/../Dockerfile")
    tag=${tag%@*}
    base=$(docker buildx imagetools inspect "$tag" | awk '$1 == "Digest:" { print $2; exit }') || fail "the base image tag $tag did not resolve"
    [[ -n $base ]] || fail "the base image tag $tag did not resolve"
    echo "Base image digest: $base ($tag)"
}

# Secrets for this run only, passed by name (-e NAME), so their values are in no file and no command line.
start_container() {
    begin start
    APP_SECRET=$(random_secret)
    MERCURE_JWT_SECRET=$(random_secret)
    export APP_SECRET MERCURE_JWT_SECRET
    docker volume create "$volume" >/dev/null || fail "docker cannot create the volume $volume"
    containers+=("$web")
    docker run -d --name "$web" -e APP_SECRET -e MERCURE_JWT_SECRET --volume "$volume:/app/var/data" --publish 127.0.0.1::7781 "$image" >/dev/null || fail "docker cannot start $image"
    published=$(docker port "$web" 7781/tcp | head -n 1)
    origin=http://localhost:7781
    reach=(--connect-to "localhost:7781:$published")
    echo "started $web on $published"
}

random_secret() {
    od -An -tx1 -N32 /dev/urandom | tr -d ' \n'
}

wait_until_migrated() {
    begin migrations
    for _ in {1..60}; do
        if [[ $(docker inspect --format '{{.State.Running}}' "$web") != true ]]; then
            docker logs "$web" >&2 || true
            fail "the container stopped"
        fi
        if docker exec "$web" php bin/console doctrine:migrations:up-to-date --no-interaction >/dev/null 2>&1 && curl --silent --output /dev/null --max-time 5 "${reach[@]}" "$origin/"; then
            echo "migrated, and the server answers"
            return
        fi
        sleep 2
    done
    docker logs "$web" >&2 || true
    fail "not migrated and answering after 120 seconds"
}

check_admin_unpublished() {
    begin admin-unpublished
    if docker port "$web" 2019/tcp >/dev/null 2>&1; then
        fail "port 2019 is published"
    fi
    echo "port 2019 is not published"
    check_admin_on_container_addresses
}

# A Linux host reaches a container's own addresses without any published port, so the admin API must answer on loopback only.
check_admin_on_container_addresses() {
    local addresses address
    read -ra addresses <<<"$(docker inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{.GlobalIPv6Address}} {{end}}' "$web")"
    ((${#addresses[@]} > 0)) || fail "$web has no network address to check the admin API on"
    for address in "${addresses[@]}"; do
        [[ $address == *:* ]] && address="[$address]"
        if "${in_server[@]}" curl --silent --output /dev/null --max-time 5 "http://$address:2019/config/"; then
            fail "the admin API answers on $address:2019, the container's network address"
        fi
        echo "nothing answers on $address:2019, the container's network address"
    done
}

check_user() {
    local uid
    begin user
    uid=$(docker exec "$web" id -u) || fail "docker exec id -u failed"
    [[ $uid == 10001 ]] || fail "the container runs as uid $uid, not 10001"
    echo "the container runs as uid 10001"
}

check_instance() {
    local output
    begin instance
    containers+=("$smoke-instance")
    output=$(timeout 180 docker run --rm --name "$smoke-instance" --entrypoint php "$image" bin/instance 2>&1) || fail "bin/instance failed: $output"
    grep -qFx '{"status":"ready","mode":"worker"}' <<<"$output" || fail "bin/instance did not report ready in worker mode: $output"
    echo "bin/instance reported ready in worker mode"
}

check_missing_secret() {
    local name=$1 output
    begin "missing-$name"
    containers+=("$smoke-no-$name")
    if output=$(env -u "$name" timeout 60 docker run --rm --name "$smoke-no-$name" -e APP_SECRET -e MERCURE_JWT_SECRET "$image" 2>&1); then
        fail "the container started without $name"
    fi
    grep -qF "set $name" <<<"$output" || fail "the entrypoint did not name $name: $output"
    echo "without $name the entrypoint stops with: $(grep -F "set $name" <<<"$output")"
}

main() {
    if [[ ${1:-} == --local && $# -eq 4 ]]; then
        check_server "$2" "$3" "$4"
        echo "smoke test passed: the checks without Docker against $2"
        return
    fi
    [[ $# -eq 1 && $1 != -* ]] || usage
    smoke_image "$1"
}

# Sourced, the script only defines its checks, so tests can run one at a time.
if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    main "$@"
fi
