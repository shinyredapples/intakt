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
