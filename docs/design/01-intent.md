# Intent

Intakt runs an autonomous build loop for a software project. It takes tasks (stored as issues) from a forge, has AI agents build them, has other AI agents review them, and merges what passes. Its design comes from running such a loop on a real project: what worked is kept, and what failed shaped the rules below.

Status: decided design (see Decisions); nothing is built yet.

## Terms

- **Spec:** what a project or feature should do, written by the owner or drafted with Intakt in the foundation flow.
- **Plan:** the spec broken into milestones and tasks, with dependencies and what may run in parallel.
- **Task:** one buildable unit of the plan, with acceptance criteria and the evidence its change must show, sized to be built and reviewed. Lanes build tasks, review panels review their changes, routing rules choose their models.
- **Issue** or **ticket:** how a forge or tracker stores a task (a GitHub or Gitea issue, a Jira ticket). Work-source plugins map tasks to them; labels, comments and links are the task's attributes as the forge shows them.

The rest of this document says *issue* where it means the forge object, and *task* for the unit of work.
