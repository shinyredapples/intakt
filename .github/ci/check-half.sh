#!/bin/sh
# One half of CI's check step, inside the copy: <name> --only|--skip <bin/check arguments> (docs/architecture/ci.md).
name=$1 half=$2
shift 2
list=$(bin/check --static-gates)
echo "gate order: $(echo "$list" | jq -r '.order | join(" ")')"
echo "$name half: bin/check started"
start=$(date +%s)
status=0
bin/check "$half=$(echo "$list" | jq -r '.static | join(",")')" "$@" || status=$?
echo "$name half: $(($(date +%s) - start))s, memory.peak $(cat /sys/fs/cgroup/memory.peak 2>/dev/null || echo unknown)," \
  "usage_usec $(sed -n 's/^usage_usec //p' /sys/fs/cgroup/cpu.stat 2>/dev/null | grep . || echo unknown)"
exit "$status"
