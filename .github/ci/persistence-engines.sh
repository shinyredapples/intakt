#!/usr/bin/env bash
# Engines run at once because one after another took 13.6 minutes, the longest CI job (docs/architecture/README.md).
set -uo pipefail

if [ "$#" -eq 0 ]; then
  echo "::error::no persistence engine given"
  exit 2
fi

out=$(mktemp -d)

# Composer's cache, restored from the Actions cache by the step before. All three engines read the one copy; mounted
# read-only (COMPOSER_CACHE_READ_ONLY, without which Composer ignores a cache it cannot write), so none writes to it.
# Readable by the containers' uid 1000; Composer downloads what it lacks.
cache=${COMPOSER_CACHE:?COMPOSER_CACHE must name the restored Composer cache directory}
mkdir -p "$cache"
chmod -R a+rX "$cache"

run_engine() {
  local name=$1 network=$2 url=$3 status
  if docker run --rm --user 1000:1000 --network "$network" --env DATABASE_URL="$url" \
      --tmpfs /home/ci:rw,exec,uid=1000,gid=1000,mode=0755,size=2g --tmpfs /tmp:rw,exec,size=512m \
      --volume "$cache:/composer-cache:ro" --env COMPOSER_CACHE_DIR=/composer-cache --env COMPOSER_CACHE_READ_ONLY=1 \
      --volume "$GITHUB_WORKSPACE:/workspace:ro" \
      "$CI_IMAGE" sh /workspace/.github/ci/run-on-copy.sh vendor/bin/phpunit --group persistence > "$out/$name.log" 2>&1; then
    status=0
  else
    status=$?
  fi
  echo "$status" > "$out/$name.status"
}

# Keyed by the service name, because an image reference may hold a registry path (a /) that is no file name.
names=()
declare -A images
for engine in "$@"; do
  IFS='|' read -r name image network url <<< "$engine"
  names+=("$name")
  images[$name]=$image
  run_engine "$name" "$network" "$url" &
done
wait

failed=0
for name in "${names[@]}"; do
  status=$(cat "$out/$name.status" 2>/dev/null || echo missing)
  echo "::group::persistence on $name, ${images[$name]} (exit $status)"
  cat "$out/$name.log" 2>/dev/null
  echo "::endgroup::"
  if [ "$status" != 0 ]; then
    echo "::error::persistence on $name failed (exit $status)"
    failed=1
  fi
done
rm -rf "$out"
exit "$failed"
