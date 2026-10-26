# Goals and non-goals

## Goals

1. **Anyone can run it.** One container image, one command, a first-run wizard. No host scripts, no hand-made users, no systemd units.
2. **Modular.** Forges, AI vendors, gates and work sources are plugins behind small interfaces.
3. **Efficient.** Idle costs nothing: no polling agents, no long-lived model sessions. Each job is one short-lived container.
4. **Uses the capacity it has.** It knows each vendor's limits (subscription windows or API spend), measures what is left, and plans model choice and pace to end each window near its ceiling, not far below it.
5. **Safe by construction.** A builder can never push, sign, merge or read secrets. What it hands back is checked in a clean place before anyone trusts it.
6. **Visible.** A dashboard shows what each lane is doing, what it costs, and what needs a human.

## Non-goals (v1)

- Multi-tenant hosting. One install serves one owner, possibly several projects later.
- Its own model hosting. Vendors are reached through their own CLIs or APIs.
- Replacing CI. The forge's CI stays the authority on whether a change is green.

## v1 scope

- Forges: GitHub, Gitea.
- Vendors: Claude Code and Codex (subscription and API key), one OpenAI-compatible API vendor.
- Runtime: Docker or Podman.
- Dependencies: bundled sidecars (images, compose services, sibling repos) and external instances; stubs later.
- Several projects per install, each new (spec and plan phase) or existing (its tickets or issues are the tasks), with a budget share per project.
- Dashboard and wizard.

## Milestones

1. Orchestrator skeleton: SQLite ledger, lanes, one forge (Gitea), one vendor, job containers, check job.
2. Reviews with JSON verdicts, two vendors, budget probes and the planner.
3. GitHub forge, wizard with detection for existing codebases and the foundation flow for new projects, dashboard. Test both paths on real repos: a Symfony monorepo, a Node app, a Go service, and an empty repo.
4. Shared memory.
5. Adoption in stages on a live project that already has a loop or a review process: shadow reviews (Intakt reviews alongside, posts nothing that counts, verdicts compared), then Intakt's verdicts count, then it builds a labeled share of tasks, then full takeover once parity is checked item by item.
6. Public release: docs, license, example project. The name `intakt-runner` is reserved as a private, unconfigured GitHub App in the `shinyredapples` organization for a possible Intakt product App; the plain `intakt` is blocked by an existing account. Each install still creates its own App, named `intakt-<project>`. The docs are published with GitHub Pages from the repository's `docs/` at shinyredapples.com/intakt (the organization site's custom domain), user guide and technical pages side by side.
