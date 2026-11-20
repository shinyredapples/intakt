# Connectivity check and outage state

`Core/Outage` answers one question: can this machine reach the outside at all? It tells a lost network apart from one host's outage. Nothing calls it yet; the classification of an interrupted reviewer will use it.

## The check

`Connectivity::reachability()` (in `Core/Outage/Contract/`) returns `Reachability::Online` or `Reachability::Offline`. `ProbedConnectivity` runs its probes in order and stops at the first one that answers:

1. A DNS lookup of the forge host (`DnsProbe`, `gethostbynamel()` through the system resolver, bounded by the resolver's own timeout and attempts, because PHP's lookup functions take no timeout).
2. An HTTPS `HEAD` request to the forge's API URL (`HttpsProbe`).
3. An HTTPS `HEAD` request to the base URL of each vendor in use: each provider descriptor of the `ProviderCatalog` whose id has at least one enabled account. A command-line provider (Claude Code, Codex) has no descriptor and so adds no probe.

The answer is offline only when every probe fails. An HTTPS probe counts as answered on any HTTP status, a 4xx or a 5xx included; only a transport error (timeout, refused connection, failed name lookup or TLS handshake) counts as a failure. It sends no credential, no body and no header of its own, follows no redirect, and waits at most `OutageServices::PROBE_TIMEOUT` seconds (`timeout` and `max_duration`, 3 seconds in `config/services.yaml`).

The hosts come from the existing configuration, not from a setting of their own: the forge URL is the parameter `GitHubForgeServices::API_URL` (`https://api.github.com`), which the scoped client `github.client` also takes as its `base_uri`, and the vendor URLs are each descriptor's `baseUrl()`. `ConfiguredProbes` builds the probe list from them, and the container builds `ProbedConnectivity` through it.

## The outage state

`OutageState` (in `Core/Outage/Domain/`) is a recorded online or offline `Reachability` with the time it began, kept in UTC. Plain code writes it and no model reads it. It is not stored yet: nothing writes it in this release.

`Core/Budget`'s `OutageWatch` is a different thing: it watches the age of usage readings, not the network.
