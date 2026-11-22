# Intakt design

The design is the authority on what Intakt does and why. Read the parts a task names; do not load the whole folder for every change.

| Part | What it holds |
|---|---|
| [Intent](01-intent.md) | what Intakt is, the terms used everywhere, and the Definitions of Ready and Done |
| [Principles](02-principles.md) | the lessons from running a loop: what is kept and what changed |
| [Goals and non-goals](03-goals-and-non-goals.md) | goals, non-goals, v1 scope, milestones |
| [Constraints](04-constraints.md) | platform, security model, vendor terms, code standards, documentation |
| [Architecture](05-architecture/) | how it works, one file per area |
| [Decisions](06-decisions.md) | what was decided on 2026-10-26 |
| [Design calls open to correction](07-open-to-correction.md) | open questions and calls a measured result may overturn |
| [Left to the Developer](08-left-to-the-builder.md) | shapes the design leaves to the first task that needs them |

Architecture:

1. [Overview](05-architecture/01-overview.md), with the team table
2. [Plugins](05-architecture/02-plugins.md)
3. [Choosing work](05-architecture/03-choosing-work.md)
4. [Labels](05-architecture/04-labels.md)
5. [Questions and steering](05-architecture/05-questions-and-steering.md)
6. [Budget and model planner](05-architecture/06-budget-and-model-planner.md)
7. [Reviews](05-architecture/07-reviews.md)
8. [Merge](05-architecture/08-merge.md)
9. [The Maintainer (formerly the maintainer agent)](05-architecture/09-maintainer-agent.md)
10. [Shared project memory](05-architecture/10-shared-project-memory.md)
11. [Setup](05-architecture/11-setup.md)
12. [New and existing projects](05-architecture/12-new-and-existing-projects.md)
13. [Dashboard](05-architecture/13-dashboard.md)
14. [Threat model: prompt injection and third-party text](05-architecture/14-threat-model.md)
15. [Designer scoring layers](05-architecture/15-designer-scoring.md)

Dashboard mocks (static pages in [`mocks/`](mocks/), with made-up projects):

- [Board](mocks/index.html)
- [Waiting on you](mocks/waiting.html) (before UX2, #3327: it still shows Copy and Why controls; the current page follows the Waiting rules in [DESIGN.md](mocks/DESIGN.md))
- [Task](mocks/task.html)
- [Settings](mocks/settings.html)
- [Components](mocks/components.html)

The single-file draft this folder was split from is in the git history (`docs/design.md`).
