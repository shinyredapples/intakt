# The per-job egress proxy (src/Core/Job/Infrastructure/EgressProxy.php); built on the engine's host as intakt-egress-proxy.
# Tag only, like .github/ci/databases.Dockerfile, because the build sandbox has no registry access: whoever has it appends @sha256:<index digest>.
FROM debian:trixie-slim

RUN apt-get update \
    && apt-get install --yes --no-install-recommends squid \
    && rm -rf /var/lib/apt/lists/*

COPY entrypoint.sh /usr/local/bin/intakt-egress-proxy

# Debian's squid user, so the proxy never runs as root and needs no capability to drop privileges.
USER proxy

ENTRYPOINT ["/usr/local/bin/intakt-egress-proxy"]
