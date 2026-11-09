#!/bin/sh
# No set -e: a failed item must be recorded and the command must still run, in reduced mode.
report="$INTAKT_OUTPUT_DIR/.start-check"
probe="$INTAKT_OUTPUT_DIR/.start-check-probe"
failures=0

record() {
    printf '%s\t%s\t%s\n' "$1" "$2" "$3" >> "$report" 2>/dev/null
    if [ "$2" = fail ]; then
        failures=$((failures + 1))
        echo "start check: $1 failed: $3"
    else
        echo "start check: $1 ok"
    fi
}

# true, not :, because a failed redirect on a special builtin ends the shell.
true > "$report" 2>/dev/null

if printf 'probe %s' "$INTAKT_JOB_ID" > "$probe" 2>/dev/null && [ "$(cat "$probe" 2>/dev/null)" = "probe $INTAKT_JOB_ID" ]; then
    record output ok ""
else
    record output fail "a probe file could not be written and read back"
fi
rm -f "$probe" 2>/dev/null

set -f
IFS='
'
for input in $INTAKT_INPUTS; do
    if [ -r "$input" ]; then
        record "input:$input" ok ""
    else
        record "input:$input" fail "the mount is missing or unreadable"
    fi
done
unset IFS

if [ -n "$INTAKT_VENDOR_HOST" ]; then
    if ! command -v curl >/dev/null 2>&1; then
        record vendor fail "curl is not in the image"
    elif curl -sS -o /dev/null --max-time 10 "https://$INTAKT_VENDOR_HOST/" 2>/dev/null; then
        record vendor ok ""
    else
        record vendor fail "the vendor is not reachable through the proxy"
    fi
fi

if [ "$failures" -gt 0 ]; then
    echo "REDUCED MODE: $failures start check item(s) failed, so this job has less than it was told it can do"
    export INTAKT_REDUCED_MODE=1
fi

set +f
exec "$@"
