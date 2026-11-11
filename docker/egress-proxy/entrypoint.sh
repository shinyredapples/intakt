#!/bin/sh
set -eu

# The whole config arrives from the orchestrator (EgressProxyDefinition), so the image holds no allowlist; the root is read-only, /run/squid a tmpfs.
: "${INTAKT_EGRESS_SQUID_CONF:?set INTAKT_EGRESS_SQUID_CONF}"
printf '%s\n' "$INTAKT_EGRESS_SQUID_CONF" > /run/squid/squid.conf

# In the foreground, so the container lives exactly as long as the proxy and its log is the container's output.
exec squid -N -f /run/squid/squid.conf
