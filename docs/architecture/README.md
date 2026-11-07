# Intakt technical pages

How Intakt works, for developers and agents. Each user-visible feature has a page here (how it works, the data it keeps, its limits, a Mermaid diagram where the flow is not obvious), written in the same change as the code. Each topic has its own page, so a change updates the page of the topic it touches and adds a page, with a line in this index, for a new topic.

Code and conventions:
- [Module layout](module-layout.md)
- [Conventions](conventions.md): logging, Doctrine mapping, message handlers, the dependency policy
- [Sample repository](sample-repository.md)
- [Upstream reproductions](upstream-reproductions.md)
- [Development diagnostics (Mate)](development-diagnostics.md)

Checks, CI and releases:
- [Quality gates](quality-gates.md)
- [Journeys](journeys.md): the harness, the test vendor and the recording hub the journeys use, and each journey
- [CI](ci.md)
- [Releases](releases.md)
- [Requirements](requirements-and-parity.md): the [external commands](requirements.md) and the [parity](parity.md) behaviors

Running Intakt:
- [Instance and probes](instance-and-probes.md)
- [Container image](container-image.md)
- [The demo with docker compose](demo-with-docker-compose.md)
- [Persistence](persistence.md)
- [MCP server for the owner](mcp-server.md)

Forges and identity:
- [Forge contract and the local git forge](forge-contract.md): forge writes, open pull request listing, verdict identity
- [GitHub App identity](github-app-identity.md)

Models and budget:
- [AI providers](ai-providers.md)
- [Capability tiers per project](capability-tiers.md): shadow mode per project
- [Vendor](vendor.md): the judge
- [Measured use](measured-use.md): start ceiling and admission, cooldowns, shared holds and outage notices

Reviews:
- [Review flow](review-flow.md)
- [Review rounds](review-rounds.md)
- [Review verdicts](review-verdicts.md)
- [Quorum policies](quorum-policies.md)
- [Finding ledger](finding-ledger.md)
- [Reviewer repository tools](reviewer-repository-tools.md)
- [Reviewer agent](reviewer-agent.md)
- [Project memory](project-memory.md)
- [Ledger](ledger.md)
- [Shadow: reading the loop's reviews](shadow-loop-reviews.md)

Dashboard:
- [Dashboard](dashboard.md)
- [Server status](server-status.md)
- [Live updates](live-updates.md)
