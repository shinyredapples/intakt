#!/usr/bin/env bash
# Runs the persistence group on several database engines at once, each in its own CI image container on its service's
# network, and reports every engine: its output in its own log group headed with its exit code. Fails when any engine
# failed. Arguments: one "name|network|database URL" per engine. Needs CI_IMAGE and GITHUB_WORKSPACE.
set -uo pipefail

out=$(mktemp -d)

run_engine() {
  local name=$1 network=$2 url=$3 status
  if docker run --rm --user 1000:1000 --network "$network" --env DATABASE_URL="$url" \
      --tmpfs /home/ci:rw,exec,uid=1000,gid=1000,mode=0755,size=2g --tmpfs /tmp:rw,exec,size=512m \
      --volume intakt-composer-cache:/composer-cache --env COMPOSER_CACHE_DIR=/composer-cache \
      --volume "$GITHUB_WORKSPACE:/workspace:ro" \
      "$CI_IMAGE" sh /workspace/.github/ci/run-on-copy.sh vendor/bin/phpunit --group persistence > "$out/$name.log" 2>&1; then
    status=0
  else
    status=$?
  fi
  echo "$status" > "$out/$name.status"
}

names=()
for engine in "$@"; do
  IFS='|' read -r name network url <<< "$engine"
  names+=("$name")
  run_engine "$name" "$network" "$url" &
done
wait

failed=0
for name in "${names[@]}"; do
  status=$(cat "$out/$name.status" 2>/dev/null || echo missing)
  echo "::group::persistence on $name (exit $status)"
  cat "$out/$name.log" 2>/dev/null
  echo "::endgroup::"
  if [ "$status" != 0 ]; then
    echo "::error::persistence on $name failed (exit $status)"
    failed=1
  fi
done
rm -rf "$out"
exit "$failed"
