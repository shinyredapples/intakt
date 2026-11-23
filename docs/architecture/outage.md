# Connectivity check and outage state

`Core/Outage` answers one question: can this machine reach the outside at all? It tells a lost network apart from one host's outage. Nothing calls it yet; the classification of an interrupted reviewer will use it.

## The check

`Connectivity::reachability()` (in `Core/Outage/Contract/`) returns `Reachability::Online` or `Reachability::Offline`. `ProbedConnectivity` runs its probes in order and stops at the first one that answers:

1. A DNS lookup of the forge host (`DnsProbe`): `gethostbynamel()` in a child PHP process (Symfony Process's `PhpProcess`) that is stopped after `OutageServices::PROBE_TIMEOUT` seconds and then counts as failed, because PHP's lookup functions take no timeout and the system resolver's retries can take longer. This deadline relies on a positive `OutageServices::PROBE_TIMEOUT`: a timeout of 0 disables it. A lookup that cannot run (no PHP executable is found, the process cannot be built because `proc_open` is disabled, or the child does not start) counts as failed too.
2. An HTTPS `HEAD` request to the origin of the forge's API URL (`HttpsProbe`).
3. An HTTPS `HEAD` request to the origin of the base URL of each hosted vendor in use: each provider descriptor of the `ProviderCatalog` whose id has at least one enabled account (Anthropic, OpenAI). A command-line provider (Claude Code, Codex) has no descriptor and so adds no probe.
4. An HTTPS `HEAD` request to the origin of the base URL of each enabled OpenAI-compatible account: the value of its `OPENAI_COMPATIBLE_BASE_URL` variable (with the account's suffix for a named account), which `config/packages/ai.php` lists in the parameter `AiServices::COMPATIBLE_BASE_URLS` and the container reads when it runs. The provider's HTTP client also requests a base URL that the RFC 3986 parser rejects for a non-ASCII (internationalized) host or a space, so such a URL is probed too: PHP's WHATWG parser (`Uri\WhatWg\Url`) reads it, and the probe goes to `https`, the host in its ASCII (punycode) form and the port. A base URL that is not absolute HTTPS (plain `http`, another scheme, a relative path, an empty host), or one the HTTP client refuses or neither parser reads (leading or trailing whitespace, a tab or a line break, a backslash, an unclosed bracket, a space in the host), counts as a failed probe: no request is sent for it, and the other probes decide the answer. So a plain HTTP answer, perhaps from a local proxy, cannot make the check online. A scoped IPv6 literal (an address with a zone ID, such as `https://[2001:db8::1%25eth0]/v1`) counts as a failed probe as well: a zone ID belongs to a link-local address on the local link, and reaching it says nothing about reaching the outside.

The answer is offline only when every probe fails. An HTTPS probe counts as answered on any HTTP status, a 4xx or a 5xx included; only a transport error (timeout, refused connection, failed name lookup or TLS handshake) counts as a failure. It sends no credential: it requests only the URL's origin (scheme, host and port, with the path `/`), so userinfo (which HttpClient would turn into a Basic header), a path or a query in a configured base URL never reaches the request. It sends no body and no header of its own, follows no redirect, and waits at most `OutageServices::PROBE_TIMEOUT` seconds (`timeout` and `max_duration`, 3 seconds in `config/services.yaml`).

The hosts come from the existing configuration, not from a setting of their own: the forge URL is the parameter `GitHubForgeServices::API_URL` (`https://api.github.com`), which the scoped client `github.client` also takes as its `base_uri` (set in `config/packages/github_forge.php`), and the vendor URLs are each descriptor's `baseUrl()` and each compatible account's base URL. `ConfiguredProbes` builds the probe list from them, and the container builds `ProbedConnectivity` through it.

## The outage state

`OutageState` (in `Core/Outage/Domain/`) is a recorded online or offline `Reachability` with the time it began, kept in UTC. Plain code writes it and no model reads it. It is not stored yet: nothing writes it in this release.

`Core/Budget`'s `OutageWatch` is a different thing: it watches the age of usage readings, not the network.
