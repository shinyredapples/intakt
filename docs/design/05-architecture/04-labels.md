# Labels

Labels are how people steer tasks from the forge, on any device, without the dashboard. They come in two kinds, and the difference is who writes them:
- **Steering labels** are set by people and read by Intakt. Each family maps to a field in the task's database row; the label is the input, the row is the state.
- **Status labels** are written by Intakt to mirror the row, so the issue list shows where each task stands. A person changing one changes nothing: Intakt puts it back and comments once. The queue never depends on them (see "Queue state lives in the database").

Default families (names are configurable per project; one value per family):

| Family | Values | Effect |
|---|---|---|
| eligibility | `intakt` | Intakt may build the task (step 1 of choosing work) |
| `priority:` | `urgent`, `high`, `normal`, `low` | queue order; no label means `normal`; split parts and follow-ups inherit it |
| `class:` | `core`, `feature`, `tooling`, `process` | slot allocation by class (step 5); the triage call proposes one when missing |
| `model:` | a capability tier, for example `strong`, `standard`, `light` | the start tier for every build of the task; escalations can still raise it |
| `plan:` | `required`, `skip` | overrides the per-size plan approval default (step 4) |
| `review:` | `full` | the full review panel on every round, even for small diffs |
| keep | `keep` | on a closed, unmerged pull request: its branch is not deleted (see [Branch cleanup](08-merge.md#branch-cleanup)) |
| `status:` (Intakt only) | `queued`, `building`, `in-review`, `waiting-on-you`, `parked`, `blocked`, `merged` | mirror of the row; exactly one at a time |

Rules:
- **Only people with write access steer.** A steering label counts only when the forge's event history shows it was applied by an account with write access, checked through the permission API (the same rule as answers in comments). A forge role that may label without write access (GitHub's triage role, for example) is ignored, and Intakt says so on the issue.
- **Intakt never applies a steering label itself**, except to copy an original's labels onto its split parts and follow-ups. A maintainer bot that added the owner's merge label to its own pull request had to be refused by hand; in Intakt a label can never stand in for an owner approval, which is always a review or a comment from the owner.
- **Labels name tiers and intents, not vendors or models.** `model:strong` survives a change of vendor or model; the owner's routing maps it (see the planner).
- **Safety rules beat labels, labels beat inference.** Sensitive paths start on the strong tier whatever `model:` says, and a protected-path change needs an approval by the merge rules (the orchestrator's by class, or the owner's) whatever `review:` says. Under the tightest weekly pacing, a task labeled for a higher tier waits instead of being built on a lower one: the owner asked for that tier explicitly.
- **Changes apply at the next decision.** A new priority reorders the queue at once; a new model tier or plan label applies to the task's next job, not the one running.
- **Two values in one family** (for example `priority:high` and `priority:low`): the one applied last wins, and Intakt removes the other with a comment.
- **Existing projects keep their labels.** A project file can map its own labels onto the families (`P1` to `priority:urgent`, `bug` to `class:core`), and a work-source plugin maps families to native fields where the forge has them (a Jira priority, a GitHub issue type or project field), so a team keeps working the way it does.
- **Setup creates the labels** with colors and descriptions that say what each one does, and never deletes or renames labels it did not create.
- **The dashboard shows and sets them.** A change made there is written to the forge as the owner, so both stay in sync.
