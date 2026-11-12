# Intakt technical pages

How Intakt works, for developers and agents. Each user-visible feature has a page here (how it works, the data it keeps, its limits, a Mermaid diagram where the flow is not obvious), written in the same change as the code. Each topic has its own page, so a change updates the page of the topic it touches and adds a page, with a line in this index, for a new topic.

Code and conventions:
- [Module layout](module-layout.md)
- [Conventions](conventions.md): logging, Doctrine mapping, message handlers, the dependency policy and which release cooldown decides what (Dependabot's or Composer's)
- [Sample repository](sample-repository.md)
- [Retried network git calls](network-git.md)
- [Bare mirror](mirror.md)
- [Build clone](build-clone.md)
- [Hand-back checks](hand-back.md): the bundle a build returns, refused when it touches main, is not a fast-forward or holds a merge, and stripped of attribution
- [Upstream reproductions](upstream-reproductions.md)
- [The published highlights page](highlights-page.md): where the assembled `docs/highlights.md` is published
- [Development diagnostics (Mate)](development-diagnostics.md)

Checks, CI and releases:
- [Quality gates](quality-gates.md): also the `workflows` gate, zizmor over the GitHub Actions workflows in `.github/`, offline; an inline `# zizmor: ignore[<audit>]` without a reason on the same line fails it
- [Journeys](journeys.md): the harness, the test vendor, the recording hub, the live round driver, the watcher start and the round stream check the journeys use, and each journey (first install and setup, live review progress on the dashboard); a failing journey names itself and its step, and the live review progress journey has a forced-break proof; `bin/journey-alarm` files or updates one urgent task for a red run
- [CI](ci.md): also the journeys on main, nightly and on demand. Dependabot updates every image CI and the app use weekly, once a release is 3 days old, except images on GHCR, such as gitleaks (no wait). Every image is pinned by tag and digest except the database images in `.github/ci/databases.Dockerfile`, which carry a tag only: whoever has registry access appends `@sha256:<index digest>` to each of its lines, and Dependabot then keeps tag and digest current like the other images.
- [Releases](releases.md)
- [Requirements](requirements-and-parity.md): the [external commands](requirements.md) and the [parity](parity.md) behaviors

Running Intakt:
- [Instance and probes](instance-and-probes.md)
- [Container image](container-image.md)
- [The demo with docker compose](demo-with-docker-compose.md)
- [Persistence](persistence.md)
- [Job containers](job-containers.md): a fresh container per job through the Docker Engine API, the egress allowlist through a per-job proxy, the start check and its reduced mode, the memory-kill cause, and the cleanup after a crash
- [Job images](job-images.md): a project's job image from its devcontainer, its Dockerfile or one generated from `composer.json`, and the probe that checks its PHP and extensions
- [Differences from the existing loop](differences-from-the-existing-loop.md): what Intakt deliberately does another way than the loop it replaces, and why
- [MCP server for the owner](mcp-server.md)

Forges and identity:
- [Forge contract and the local git forge](forge-contract.md): forge writes, open pull request listing, verdict identity
- [GitHub App identity](github-app-identity.md)

Models and budget:
- [AI providers](ai-providers.md)
- [Capability tiers per project](capability-tiers.md): the mode per project (live or reviewer-for-loop)
- [Vendor](vendor.md): the judge
- [Measured use](measured-use.md): start ceiling and admission, cooldowns, shared holds and outage notices
- [Round cost](round-cost.md): the price per model and the cost line of `intakt:review`
- [Provider failure cases](provider-failure-cases.md): the scripted runs of plan T25 and how each round ends

Builds:
- [Builder prompt](builder-prompt.md): the build job's prompt, every untrusted input fenced, and the gate test that proves it

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
- [The weekly report: the loop's reviews and Intakt's](weekly-report.md)

Dashboard:
- [Dashboard](dashboard.md)
- [Server status](server-status.md)
- [Live updates](live-updates.md)
