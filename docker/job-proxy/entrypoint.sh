#!/bin/sh
# Forwards only the hosts in INTAKT_PROXY_ALLOW (comma separated) and logs "refused on filtered domain" for any other; a job runs behind it on a network with no other way out.
set -eu

allow=/tmp/allow
config=/tmp/tinyproxy.conf

: > "$allow"
old_ifs=$IFS
IFS=,
for host in $INTAKT_PROXY_ALLOW; do
    [ -n "$host" ] && printf '^%s$\n' "$(printf '%s' "$host" | sed 's/\./\\./g')" >> "$allow"
done
IFS=$old_ifs

cat > "$config" <<CONF
Port 8888
Listen 0.0.0.0
Timeout 600
LogFile "/dev/stdout"
LogLevel Info
ConnectPort 443
ConnectPort 80
Filter "$allow"
FilterDefaultDeny Yes
FilterExtended Yes
CONF

exec tinyproxy -d -c "$config"
