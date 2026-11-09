#!/bin/sh
# tinyproxy has no allowlist of plain host names, so each allowed host becomes an anchored pattern under FilterDefaultDeny.
# No LogFile: tinyproxy refuses /dev/stdout as one, and without it a foreground tinyproxy logs to stdout, where the engine keeps the refusals the runner reads.
set -eu

allow="${TMPDIR:-/tmp}/allow"
config="${TMPDIR:-/tmp}/tinyproxy.conf"

: > "$allow"
old_ifs=$IFS
IFS=,
for host in $INTAKT_PROXY_ALLOW; do
    [ -n "$host" ] && printf '^%s$\n' "$(printf '%s' "$host" | sed 's/\./\\./g')" >> "$allow"
done
IFS=$old_ifs

cat > "$config" <<CONF
Port ${INTAKT_PROXY_PORT:-8888}
Listen 0.0.0.0
Timeout 600
LogLevel Info
ConnectPort 443
ConnectPort 80
Filter "$allow"
FilterDefaultDeny Yes
FilterType ERE
CONF

exec tinyproxy -d -c "$config"
