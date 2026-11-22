# Intakt technical pages

How Intakt works, for developers and agents. Each user-visible feature has a page here (how it works, the data it keeps, its limits, a Mermaid diagram where the flow is not obvious), written in the same change as the code. Each topic has its own page, so a change updates the page of the topic it touches and adds a page, with a line in this index, for a new topic.

Code and conventions:
- [How it is built](how-it-is-built.md): the stack (PHP 8.5, Symfony 8.2, Symfony AI, FrankenPHP worker mode with Mercure, Messenger and the Scheduler) and the interfaces, with no public resource API in v1
- [Module layout](module-layout.md)
- [Conventions](conventions.md): logging, Doctrine mapping, message handlers, the dependency policy and which release cooldown decides what (Dependabot's, Composer's or the import map's)
- [Sample repository](sample-repository.md): the demo's sample change and the review bench (`intakt:bench:review`, `intakt:bench --repeat`, the result file under `var/bench/`, agreement of repeated runs, failure causes printed before the figures)
- [Retried network git calls](network-git.md)
- [Bare mirror](mirror.md)
- [Build clone](build-clone.md)
- [Branch push](branch-push.md)
- [Hand-back checks](hand-back.md): the bundle a build returns, refused when it touches main, is not a fast-forward, holds a merge or a secret, stripped of attribution and redacted of mounted credentials; the pull request text scanned too, and a failure sent back once, then handed over
- [Check job](check-job.md): the pre-push check of an accepted bundle in a fresh clone through the gate plugin, the lost-content rule, the verdict read from the job's output directory only, and the pull request text rule (Evidence references, body cap)
- [Watchdog over main](watchdog.md): the rules that classify main's new commits: a recorded merge head and the commits its merge brought in above its base pass before any signature check, an owner-signed commit passes, an unsigned commit, an unknown key and a rewrite of main's history are violations, each of which halts the project with an owner card that only the owner clears
- [Protected paths of a head](protected-paths.md): the owner's list per project, read from the whole change and each commit; unreadable counts as protected
- [Work intake from GitHub issues](work-intake.md): the work source contract, the `eligibility_label` watch key and the stored state of the GitHub work source
- [Upstream reproductions](upstream-reproductions.md)
- [The published highlights page](highlights-page.md): where the assembled `docs/highlights.md` is published
- [Development diagnostics (Mate)](development-diagnostics.md): Mate's tools, and the Symfony profiler in `dev` and `test` with its allow-list of collectors

Checks, CI and releases:
- [Quality gates](quality-gates.md): also the `workflows` gate, zizmor over the GitHub Actions workflows in `.github/`, offline; an inline `# zizmor: ignore[<audit>]` without a reason on the same line fails it; the `phpstan` gate refuses a model message built outside `PromptAssembly` and judges `src/`, `bin/` and `config/`; a message built under `tests/` is not judged
- [The journey page](journey-page.md): `bin/journey` regenerates the At a glance table, the milestone table and the chart regions of `docs/journey.md` from the GitHub API and `docs/journey/usage.jsonl`, writing only between marker comments, and draws the charts and the Mermaid timeline as SVG files in `docs/journey/`
- [Journeys](journeys.md): the harness, the test vendor, the recording hub, the live round driver, the dashboard of the journey's own, the watcher start, the round stream check and the concurrent consumer support the journeys use, and each journey (first install and setup, live review progress on the dashboard); a failing journey names itself and its step, and the live review progress journey has a forced-break proof; `bin/journey-alarm` files or updates one urgent task for a red run
- [CI](ci.md): also the journeys, on every push to main, on a pull request that needs the app image or touches the journeys' test code, and on main nightly and on demand. Dependabot updates every image CI and the app use weekly, once a release is 3 days old, except images on GHCR, such as gitleaks (no wait). Every image is pinned by tag and digest except the database images in `.github/ci/databases.Dockerfile`, which carry a tag only: whoever has registry access appends `@sha256:<index digest>` to each of its lines, and Dependabot then keeps tag and digest current like the other images.
- [Releases](releases.md)
- [Requirements](requirements-and-parity.md): the [external commands](requirements.md) and the [parity](parity.md) behaviors

Running Intakt:
- [Instance and probes](instance-and-probes.md)
- [Connectivity check and outage state](outage.md): offline only when the forge's DNS lookup and every HTTPS probe of the forge and the vendors in use fail; any HTTP status counts as an answer; no credential is sent
- [Container image](container-image.md)
- [The demo with docker compose](demo-with-docker-compose.md)
- [The demo recording](demo-recording.md): `intakt:demo:record` writes the ledger events and the captured live updates of a change's review rounds into one file, with the date, the models and the Intakt version, `intakt:demo:replay` writes its ledger events into the demo project and publishes its live updates at the recorded pace, and `intakt:demo:remove` deletes the demo project with its replayed ledger rows
- [Persistence](persistence.md)
- [Job containers](job-containers.md): a fresh container per job through the Docker Engine API, the egress allowlist through a per-job proxy, the start check and its reduced mode, the memory-kill cause, and the cleanup after a crash
- [Job images](job-images.md): a project's job image from its devcontainer, its Dockerfile or one generated from `composer.json`, and the probe that checks its PHP and extensions
- [Differences from the earlier loop](differences-from-the-earlier-loop.md): what Intakt deliberately does another way than the build loop that developed Intakt, and why
- [MCP server for the owner](mcp-server.md)

Forges and identity:
- [Forge contract and the local git forge](forge-contract.md): forge writes, open pull request listing, verdict identity
- [GitHub App identity](github-app-identity.md)
- [Pull requests handed to Intakt](handed-to-intakt.md): an open pull request whose `change_opened` line says Intakt's App created it gets one `change_handed_to_intakt` line; when no opening record decides, the author GitHub sets does, and an answer that it is someone else gets a `change_not_handed_to_intakt` line. A recorded answer, either way, means the forge is not asked again; title, labels and branch never decide. Intakt's review of a handed-over pull request is published in any mode, and an approved protected head gets a `pending` status, an owner ask in comments of its own listing every protected path and one `owner_asked` line once the ask is posted, and a head judged before the hand-over is published once it is recognized
- [Workflow risk scan](workflow-risk-scan.md): one workflow file against the hosted, read-only, secret-free envelope; any form the strict line reader does not read is `unreadable`; the changed paths that are part of the CI definition; the head's, main's and the merged workflows of a change and whether its push waits for the owner; holding a risky CI change until the owner's `approve <sha>`, with its complete diff on the issue

Models and budget:
- [AI providers](ai-providers.md)
- [Capability tiers per project](capability-tiers.md): the mode per project (live or reviewer-for-loop)
- [Model choice](model-choice.md): the routing rules that pick the build tier
- [Vendor](vendor.md): the judge
- [Measured use](measured-use.md): start ceiling and admission, cooldowns, shared holds and outage notices
- [Round cost](round-cost.md): the price per model and the cost line of `intakt:review`
- [Provider failure cases](provider-failure-cases.md): the scripted runs of plan T25 and how each round ends

Builds:
- [The build lane's review cap and in-flight limit](lanes.md): `ReviewCap` admits a build while fewer heads than the cap wait for a reviewer or are in review; heads waiting for CI, a runner or landing do not count; `HeadsInFlightLimit` admits a build while fewer heads than the limit (twice the cap by default) are in flight, counting every pushed, unmerged head
- [Builder prompt](builder-prompt.md): the build job's prompt, every untrusted input fenced, and the gate test that proves it
- [Build task](build-task.md): `intakt:build:task` reads the task, updates the mirror, makes the build clone, runs the build job, checks its hand-back, runs the pre-push check and pushes the branch signed, recording each step in the ledger
- [Build job](build-job.md): the Claude Code CLI on the tier's model in a job container, and the bundle and pull request text it returns, or the structured question it ends with instead

Reviews:
- [Review flow](review-flow.md)
- [Review job](review-job.md): `ReviewJob`, which runs one reviewer's CLI in a job container in the project's review image with only its vendor's host allowed and reads its answer, and the job's script, `docker/job/review.sh`, which refuses a checkout that is writable, carries settings a clone does not write, or is not exactly the head, and a job without its proxy, before it runs the CLI
- [Boot step](boot-step.md): a commit booted once with `bin/instance` (main's for a head), its outcome booted, failed or crashed, never an exception
- [Review rounds](review-rounds.md)
- [Review verdicts](review-verdicts.md)
- [Quorum policies](quorum-policies.md)
- [Finding ledger](finding-ledger.md)
- [Reviewer repository tools](reviewer-repository-tools.md)
- [Reviewer agent](reviewer-agent.md)
- [Prompt assembly](prompt-assembly.md): `PromptAssembly` is the only class that builds a model message, with literals and `FencedText` only, the role prompt only through `systemWithRole()`, enforced by the `phpstan` gate on code under `src/`, `bin/` and `config/`
- [Project memory](project-memory.md)
- [Ledger](ledger.md)
- [The weekly report: the loop's reviews and Intakt's](weekly-report.md)

Dashboard:
- [Dashboard](dashboard.md)
- [Status read model](status.md): the status JSON per project and its schema, read with no queued message (only Review's replay-mark queries on the synchronous query bus)
- [Server status](server-status.md)
- [Live updates](live-updates.md)
