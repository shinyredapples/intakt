# Intakt technical pages

How Intakt works, for developers and agents. Each user-visible feature has a page here (how it works, the data it keeps, its limits, a Mermaid diagram where the flow is not obvious), written in the same change as the code. Each topic has its own page, so a change updates the page of the topic it touches and adds a page, with a line in this index, for a new topic.

## How Intakt works

Intakt reviews code changes with a panel of AI models and can build changes from tasks. Reviews and builds are recorded as events in one append-only ledger, and the dashboard reads from it.

### The parts

- **Review panel:** the reviewers of a change, each a model on a capability tier. Each reviewer reads the change with read-only tools and returns a verdict: `approve`, `changes` or `blocked`, with findings rated P1 to P3.
- **Judge:** one more model call that sorts the panel's findings into the act list (what to fix now) and follow-ups. Before it runs, plain code drops a finding that quotes code when none of its quoted code fragments is in the change; a finding without a checkable quote goes to the judge.
- **Rounds and passes:** a round is one review of one head of a change. A change can have several rounds, for example one for each new head. The dashboard calls a round a "Review pass".
- **Quorum policy:** decides the outcome from the votes: `all`, `majority` or `one-strong-plus-one`.
- **Dashboard:** server-rendered web pages that show the board, each review, what waits on you, and Trends. Running reviews update the open page live.
- **MCP:** an MCP server, so your own agent can list projects, start a review and read its status, verdicts and act list.
- **Forge and GitHub:** the forge is where changes live. A local git repository works as a forge, and so does GitHub through Intakt's GitHub App. Without a GitHub App, Intakt publishes nothing to GitHub.
- **Quality gates:** `bin/check` runs the checks of this repository (coding standards, static analysis, tests, secret scans and more); the journeys run separately, with `bin/check --journeys`. CI runs the same command, with the pull request's arguments.
- **Build side:** `intakt:build:task` turns a task into a branch. A builder runs in a fresh job container, Intakt checks what it hands back, runs the checks again itself, and pushes the branch signed.

### One review, start to verdict

1. A review starts from `intakt:review`, the MCP tool `start_review` or the button on the dashboard.
2. Intakt checks that every tier the panel needs has a model. If not, it refuses and opens no round.
3. It opens a round and queues one job per reviewer, so the reviewers work in parallel.
4. Each reviewer reads the change and returns a verdict with its findings.
5. When every reviewer is in, the round is complete and its findings join the change's finding list.
6. The quote check drops a finding that quotes code when none of its quoted code fragments is in the change. A finding without a checkable quote is kept. The judge marks the rest act, defer or reject.
7. The quorum policy decides the outcome, and the act list holds every kept P1 and P2 plus the P3s marked act. A P3 the reviewer flags as a gate gap never reaches the act list: marked act or defer, it becomes a follow-up.
8. `intakt:review` prints the outcome and the act list. The dashboard shows the same review. A round in reviewer-for-loop mode, or of a pull request handed to Intakt, also publishes its status and a comment on GitHub, when a GitHub App is set.

[Review flow](review-flow.md) draws the whole flow as a diagram.

## Detailed reference

The pages below are detailed reference for contributors. Each one describes its part exactly, with the classes, the data it keeps and its limits. Start with the overview above and open a page when you work on that part.

### Code and conventions

- [How it is built](how-it-is-built.md): the stack (PHP 8.5, Symfony 8.2, Symfony AI, FrankenPHP) and the interfaces.
- [Module layout](module-layout.md): which folder under `src/` holds what.
- [Conventions](conventions.md): logging, Doctrine mapping, message handlers and the dependency policy.
- [Sample repository](sample-repository.md): the demo's sample change and the review bench.
- [Development diagnostics (Mate)](development-diagnostics.md): Mate's tools and the Symfony profiler in development.
- [Upstream reproductions](upstream-reproductions.md): small programs that check bugs in the projects Intakt depends on.
- [The published highlights page](highlights-page.md): where `docs/highlights.md` is published.

### Reviews

- [Review flow](review-flow.md): how one review goes from its start to the act list, as a diagram.
- [Review rounds](review-rounds.md): how a round runs its reviewers in parallel and survives a restarted worker.
- [Review verdicts](review-verdicts.md): what a reviewer returns and how its answer is read.
- [Quorum policies](quorum-policies.md): how the votes decide the outcome.
- [Finding ledger](finding-ledger.md): how a finding keeps its id across the rounds of one change.
- [Review job](review-job.md): how one reviewer's CLI runs in a job container.
- [Reviewer agent](reviewer-agent.md): the reviewer that runs through Symfony AI.
- [Reviewer repository tools](reviewer-repository-tools.md): the three read-only tools a reviewer reads the change with.
- [Prompt assembly](prompt-assembly.md): the one class that builds every message sent to a model, and the only places that may build its carriers (`ReviewerRole`, `RoundReviewer`, `ReviewerConversation`).
- [Boot step](boot-step.md): booting a commit once to see whether it starts.
- [Project memory](project-memory.md): what Intakt learned about a project, handed to reviewers.
- [Ledger](ledger.md): the append-only event log that everything else reads.
- [The weekly report: the loop's reviews and Intakt's](weekly-report.md): a weekly comparison of review results.

### Dashboard

- [Dashboard](dashboard.md): the web pages and the few buttons that act.
- [Live updates](live-updates.md): how a running review reaches the open page without a reload.
- [Status read model](status.md): the status JSON per project.
- [Server status](server-status.md): reading the web server's own state from inside the container.

### Models and budget

- [AI providers](ai-providers.md): which model providers Intakt can use and how keys are found.
- [Vendor](vendor.md): the layer that calls the models, and the judge.
- [Capability tiers per project](capability-tiers.md): the mode of each project.
- [Model choice](model-choice.md): the rules that pick the tier a build starts on.
- [Measured use](measured-use.md): how Intakt measures and paces its use of subscription windows, with cooldowns and holds.
- [Round cost](round-cost.md): the price per model and the cost of a round.
- [Provider failure cases](provider-failure-cases.md): how a round ends when a provider fails.

### Forges and identity

- [Forge contract and the local git forge](forge-contract.md): how Intakt reads and writes changes on a forge.
- [Branch cleanup](branch-cleanup.md): how a forge lists, judges and deletes branches for branch cleanup.
- [GitHub App identity](github-app-identity.md): how Intakt acts on GitHub as its App.
- [Pull requests handed to Intakt](handed-to-intakt.md): how Intakt decides which pull requests are its own to review and publish on.
- [Work intake from GitHub issues](work-intake.md): how labeled issues become tasks, and how `GitHubWorkSource` picks the next one.
- [Workflow risk scan](workflow-risk-scan.md): how a change to the CI workflows is checked, and held for the owner when risky.

### Builds

- [Build task](build-task.md): `intakt:build:task`, every step from the task to the pushed branch.
- [Build job](build-job.md): the builder's CLI in a job container and what it returns.
- [Builder prompt](builder-prompt.md): what a builder is told, with every untrusted input fenced.
- [Hand-back checks](hand-back.md): the checks on what a build returns before anything is pushed.
- [Check job](check-job.md): the pre-push run of the project's checks in a fresh clone, and the pull request text rule (Evidence references, body cap).
- [The build lane's review cap and in-flight limit](lanes.md): when a new build may start.
- [Bare mirror](mirror.md): the local copy of each project's repository.
- [Build clone](build-clone.md): the fresh clone a build works in.
- [Retried network git calls](network-git.md): how a failed network git call is tried again.
- [Branch push](branch-push.md): signing, pushing and opening the pull request.
- [Protected paths of a head](protected-paths.md): the paths that need the owner's approval.
- [Watchdog over main](watchdog.md): the rules that check every new commit on main.

### Checks, CI and releases

- [Quality gates](quality-gates.md): every gate `bin/check` runs.
- [CI](ci.md): what runs on GitHub Actions and when. Every image is pinned by tag and digest except the database images in `.github/ci/databases.Dockerfile`, which carry a tag only: whoever has registry access appends `@sha256:<index digest>` to each of its lines, and Dependabot then keeps tag and digest current like the other images.
- [Journeys](journeys.md): end-to-end tests of what a user does in a fresh install.
- [Journey check](journey-check.md): `bin/journey-check`, user journeys walked in a real browser against a running Intakt.
- [The install check](install-check.md): `bin/install-check` installs the project the way the README says, on a clean clone, and reports a failing step.
- [The journey page](journey-page.md): how `docs/journey.md` and its charts are regenerated.
- [Releases](releases.md): the labels each pull request gets from its title type, for the release notes.
- [Requirements](requirements-and-parity.md): the [external commands](requirements.md) and the [parity](parity.md) behaviors.

### Running Intakt

- [The demo with docker compose](demo-with-docker-compose.md): the one-command demo.
- [The demo recording](demo-recording.md): recording a real review and playing it back in the demo.
- [Container image](container-image.md): the image and its smoke test.
- [Instance and probes](instance-and-probes.md): running an install, and probing it with network access cut.
- [Connectivity check and outage state](outage.md): when Intakt counts itself as offline.
- [Persistence](persistence.md): the supported databases.
- [Job containers](job-containers.md): a fresh container per job, with a network allowlist.
- [Job images](job-images.md): how a project's job image is made and checked.
- [MCP server for the owner](mcp-server.md): the MCP tools and how they are secured.
- [Differences from the earlier loop](differences-from-the-earlier-loop.md): what Intakt does differently from the build loop that developed it, and why.
