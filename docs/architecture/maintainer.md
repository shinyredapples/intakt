# The Maintainer's flow report

In short: `Core/Maintainer` counts three figures of the Maintainer's weekly flow review in plain code, from the open tasks the work source returns. No model reads or counts anything here. The part that runs the weekly session, files findings and opens owner cards is not built yet.

## Where the data comes from

`WorkSource::openWork()` returns an `OpenWork` (open tasks, and the due dates of milestones with no open task), or null when the listing came back short. `GitHubWorkSource` builds the tasks from the intake plan, so each one is eligible and was written and last edited by an account with write access. Each `Wait` carries whether its writer was trusted; the report counts only trusted ones. The holder is `Person` for a vouched `status:waiting-on-you` label, `Session` for a vouched `status:parked` label.

Not read yet: umbrella part comments and milestones with no open task. The stored rows keep neither, so `GitHubWorkSource` returns no empty milestones and wait lines come from task bodies only. The domain already handles both.

## The figures

`FlowReport::of($work, $now, $urgentThreshold)` has no clock and no I/O.

- Hand-overs: every open task with a holder, with the number of distinct open tasks that wait on it directly or through a chain (a visited set ends a cycle), most-held first, then by number.
- Overdue milestones: open tasks per due date earlier than now; a past-due milestone with no open task goes to the list of milestones to close.
- Priority mix: the share of each priority; `tooManyUrgent` is true when the urgent share is strictly above the threshold. Plain code never relabels.

The door is `intakt:maintainer:flow-report <project>` (`ShowFlowReportCommand`); it takes the time from `ClockInterface` and strips control characters from titles.