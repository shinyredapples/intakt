# Intent

Intakt runs an autonomous build loop for a software project. It takes tasks (stored as issues) from a forge, has AI agents build them, has other AI agents review them, and merges what passes. Its design comes from running such a loop on a real project: what worked is kept, and what failed shaped the rules below.

Status: decided design (see Decisions); nothing is built yet.

## Terms

- **Product owner** (short: the **owner**): the person who owns the project. Sets goals and priorities, makes the taste decisions, and holds the two approval floors (the approval system itself, and secrets and permissions).
- **Team:** the model roles that do the work, with traditional team titles: Tech lead, Designer, Developer, Code reviewers, Security engineer, AI engineer and Maintainer. What each one does, and what each was formerly called, is in the team table of the [Overview](05-architecture/01-overview.md#the-team). Machinery keeps technical names (orchestrator, scheduler, watchdog, checks, dashboard).
- **Spec:** what a project or feature should do, written by the owner or drafted with Intakt in the foundation flow.
- **Plan:** the spec broken into milestones and tasks, with dependencies and what may run in parallel.
- **Task:** one buildable unit of the plan, with acceptance criteria and the evidence its change must show, sized to be built and reviewed. Lanes build tasks, Code reviewers review their changes, routing rules choose their models.
- **Issue** or **ticket:** how a forge or tracker stores a task (a GitHub or Gitea issue, a Jira ticket). Work-source plugins map tasks to them; labels, comments and links are the task's attributes as the forge shows them.

The rest of this document says *issue* where it means the forge object, and *task* for the unit of work.

## Definition of Ready

This is the only statement of it; every readiness rule in the design points here. The Tech lead owns it. A task is *Ready* to build when:

1. It states one outcome, an observable result and acceptance criteria.
2. It fits the size contract, or it has been split into parts that do ([Choosing work](05-architecture/03-choosing-work.md)).
3. It has a plan: the files, the approach and the risks.
4. Every item of its Evidence list can be produced inside a job's sandbox; evidence only the owner or the orchestrator can produce is marked as such.
5. It names the docs the change will touch.
6. It names what it waits for (`Waits for #n`) and, for a split part, its source.

For a new project, the spec is Ready to plan when the Tech lead can break it into tasks that meet the six points above.

## Definition of Done

This is the only statement of it; every self-check rule in the design points here. The Developer owns it together with the checks. A change is *Done* when:

1. Every acceptance criterion is met, and every Evidence item is shown from a running install in the pull request.
2. The project's checks pass: the Developer's own run of the fast checks before it hands back, then the check job in a clean place and CI, which are the ones trusted.
3. The docs it touches state what the code now does: every name and behavior it changed was searched for in the docs and corrected.
4. The recurring blocking findings in the Developer's briefing were checked against it.

Done is what the Developer hands back; the Code reviewers then judge it, and only a merge ends the task.
