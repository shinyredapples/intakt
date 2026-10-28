# Decisions

1. License: MIT.
2. Orchestrator: PHP and Symfony (see Platform).
3. Several projects per install in v1: new projects through a spec and a plan, existing projects through their tickets or issues; a fixed weekly budget share per project, editable on the dashboard, with unused share flowing to the others.
4. Job images: the project's devcontainer or Dockerfile, a generated one, or an owner-supplied prebuilt image.
5. Default protected paths for projects without gates: strict (CI definitions, hook configuration, the check command, agent instructions, dependency manifests, Intakt's own project file).
6. Bundled dependencies that are Intakt projects: tested against their main by default, switchable to their last release per dependency.
7. Plan approval: adaptive (on after a rejected change, off after five clean merges), with size as the starting default.
8. Overlap forecasts: measured (forecast against actual overlap is logged); serialization only at high forecast confidence.
9. Commit signing under the App: merges are created through the forge API, so they are verified as the App and the owner does not vouch for each merge; branch protection can require verified commits.
10. Forges and trackers in v1: GitHub and Gitea; Jira is the first tracker plugin after v1.
11. Spec and plan live in the repository (`docs/spec.md`, `docs/plan.md`), versioned and reviewed; the plan's tasks are created as issues.
12. Vendor accounts: subscriptions, personal and company API keys and cloud sessions, equally configurable per vendor, with priorities, roles and capped paid overflow (see Budget and model planner).
13. One owner per install in v1; collaborators work through the forge's own permissions.
14. Distribution: a Docker image (Intakt is an application, not a library).
15. The name stays Intakt.
16. PHP 8.5 and its features (decided 2026-10-26, replacing 8.4): minimum 8.5.12, the release that fixes PHP2Shell (CVE-2026-109618 and CVE-2026-109619, unauthenticated remote code execution). CI refuses an older runtime.
17. FrankenPHP worker mode (decided 2026-10-26): the dashboard, the local API and the MCP server run in FrankenPHP worker mode with its built-in Mercure hub for live updates; the Messenger consumers and the Scheduler run as separate long-running processes from the same image. Symfony Runtime's own `FrankenPhpWorkerRunner` (symfony/runtime 7.4 and later, detected automatically; Intakt runs 8.1) serves worker mode, so no extra runtime package is needed. This deliberately runs Symfony AI in a long-lived process, to find leaks and state bugs in the beta while they can still be fixed.
18. The orchestrator is the maintainer (decided 2026-10-28): it decides every hand-over it can, approves protected changes that only tighten or keep the rules (both reviewers agree, gate code proven additive), holds tasks that wait on something outside their change and releases them on its own, and asks the owner only for a decision it cannot make, for loosening changes, for the CI definition and for the rules of its own approvals (see The maintainer agent and Merge).
