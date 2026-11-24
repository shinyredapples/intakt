# Branch cleanup

Plan task T30 (#24). The weekly job that deletes the branches a busy loop leaves behind, following [Branch cleanup](../design/05-architecture/08-merge.md#branch-cleanup) of the design. It warns a week ahead, deletes only what that warning named, and keeps every deleted head as an archive ref in the project's [bare mirror](mirror.md), so a deleted branch can be restored.

## When it runs

`Core/BranchCleanup/UseCase/CleanUpBranches/BranchCleanupSchedule` (`#[AsSchedule('branch_cleanup')]`) sends one `CleanUpBranches` message every week, first on Monday 2026-11-23 03:00 UTC. The schedule is stateful (the cache pool) with `processOnlyLastMissedRun`, because its consumer restarts every hour: a run missed while it was down comes once at its next start. In the image the scheduler process consumes it beside the watcher tick and the retention task (`messenger:consume scheduler_watcher scheduler_retention scheduler_branch_cleanup`, `docker/entrypoint.sh`). `CleanUpBranchesHandler` runs every project of the watch section (`WatcherServices::PROJECTS`) in turn; a project that fails is logged at error level (`Branch cleanup of <project> failed; nothing more of it was deleted this week.`) and the others still run.

`php bin/console intakt:branches:clean-up <project>` runs the same cleanup of one project at once. It prints one line per warned branch (`warned <branch> <head> deletable from <date>`), per deleted branch (`deleted <branch> <head> archived as <ref>`), per stale branch and per branch that could not be deleted, then the counts, and exits 1 when a deletion failed. Both take the lock `branch-cleanup-<project>` without waiting, so a second run of a project while one runs is refused (`CleanupNotRun`).

## One run

`ProjectCleanup::run()`:

1. Lists the branches from the project's forge (`Plugin/Forge/Contract/ForgeBranches`, below), with the default branch; a listing without a default branch judges nothing.
2. Updates the mirror from the watch section's `repository` (`BranchArchive::refresh()`, which runs `UpdateMirror`); a mirror that cannot be updated stops the run before anything is deleted.
3. Filters the branches cleanup may touch, then judges each one with the rule chain (both `Domain/BranchRules`, below).
4. Deletes a branch only when it is due now and a stored warning names the same branch at the same head, given at least 7 whole UTC days before (`BranchWarning::allowsDeletionAt()`). The head is archived first, then the forge deletes the branch.
5. Warns about every branch that is due a week from now (judged at now + 7 days) and has no deletion this run. A warning already stored for the same head keeps its first moment; a branch that moved since gets a new warning and a new week.
6. Replaces the project's stored warnings with this run's list in one transaction, so a branch that is no longer due loses its warning.
7. Logs one notice: `Branch cleanup of <project>: <n> branches warned (<branch> deletable from <date>, ...), <n> deleted, <n> stale.`

A deletion that fails (the archive ref could not be written, the branch moved, the forge refused) is reported for that branch and the run goes on; the warning stays, so the next run tries again.

## The rules

`BranchRules::touchable()` is a pipe (`|>`) of four filters: only branches with Intakt's prefix `intakt/` (`CleanupPolicy::INTAKT_PREFIX`) unless the project opts in, never the default branch, never a protected branch (as the forge reports it), never a release branch (a name starting with `release` or `releases`, then `/` or `-`).

`BranchRules::verdictOf()` is a pipe of steps over a `Domain/Judgement`; the first step that decides wins:

| Step | Verdict |
| --- | --- |
| the mirror does not hold the head | keep |
| the pull request of the current head is merged | due |
| it is closed unmerged | due 30 days after it closed, unless it carries the label `keep`; keep before that |
| it is open | keep |
| no pull request, and the branch is claimed | stale after 30 days without commits, else keep; never due |
| no pull request, last commit 60 days ago or more | due |
| no pull request, last commit 30 days ago or more | stale |
| otherwise | keep |

A branch is claimed when it is named in the project's `keep_branches` list, or when a person committed to it: `SignedCommits::verify()` checks every commit of the head that the default branch lacks against the orchestrator's signing key, and any commit without that signature is a person's. A check that cannot answer also claims the branch. The age of a branch is the committer time of its head (`git log -1 --format=%ct` in the mirror).

Only a pull request opened for the branch's current head counts, because one made for an older head says nothing about commits pushed after it.

## Project settings

Two keys of a project in the owner's config file (`config/intakt.yaml`, or the file `INTAKT_CONFIG` names), validated by `Infrastructure/BranchCleanupConfiguration` and passed as the parameter `BranchCleanupServices::PROJECTS`: `clean_other_branches` (boolean, default false) opts in to branches without Intakt's prefix, and `keep_branches` (a list of branch names, each not empty, default none) claims branches by exact name. `config/packages/ai.php` takes both keys off each project before the AI module's tree reads it.

## Forges

`ForgeBranches` is tagged; `Infrastructure/ProjectForges` asks each implementation whether it serves the project.

- **GitHub** (`Plugin/GitHubForge/Infrastructure/GitHubForgeBranches`, the projects with a `github` key): reads the repository's `default_branch`, then `GET /repos/<owner>/<repo>/branches` (100 per page, at most 10 pages, then the read fails), each with its head and `protected`. The change of a branch is `GET .../pulls?state=all&head=<owner>:<branch>`, keeping only pull requests whose head is the branch's head; an open one wins, else the newest closed one, merged when it has `merged_at`. Every field is checked; a missing or wrong field fails the read, and the run deletes nothing. A deletion reads the branch again and refuses when it moved, then sends `DELETE .../git/refs/heads/<branch>` (each part of the name URL-encoded) and expects 204. A restore sends `POST .../git/refs`, which GitHub refuses when the branch exists again.
- **Local repository** (`Plugin/LocalGitForge/Infrastructure/LocalForgeBranches`, the projects without a `github` key): lists `refs/heads/` with `for-each-ref`, the default branch being the one `HEAD` names; no branch is protected and no branch has a pull request, so only the rules without a pull request apply. A deletion is `update-ref -d <ref> <head>`, which fails when the branch moved. A restore fetches the archive ref from the mirror (over `file` only) and creates the branch with `update-ref <ref> <head> ''`, which fails when it exists again.

## Archive refs

`Core/Git/UseCase/ArchiveBranch/MirrorBranchArchive` (behind `Core/Git/Contract/BranchArchive`) writes `refs/intakt/archive/<UTC moment as YYYYMMDDTHHMMSSZ>/<branch>` in the mirror with `update-ref --no-deref <ref> <head> ''`, so an archive is never overwritten. The namespace lies outside `refs/heads/` and `refs/tags/`, so no push of branches or tags carries it, and `UpdateMirror` leaves it out of its pruning fetch with the negative refspec `^refs/intakt/archive/*`, so the refs survive the next update although the remote never holds them.

`php bin/console intakt:branches:restore <project> <branch>` (`UseCase/RestoreBranch`) takes the newest archive of the branch (the last ref name in sort order) and has the forge create the branch at that head. It fails when the mirror keeps no archive of the branch. On GitHub the commit must still exist there, which a pull request's own ref keeps.

Limit: the archive refs live only as long as the mirror. In the container image the mirrors are in `/app/var/mirrors`, outside the data volume `/app/var/data`, so a replaced container loses them, and a mirror that is cloned again starts without archives.

## Data

The table `branch_cleanup_warning` (migration `Version20261124100000`, entity `Domain/BranchWarning`): `project` and `branch` as the key, the warned `head` and `warned_at` as text in UTC with microseconds, like the watcher's tables.

## Builder choices

- The design names the branch prefix without spelling it; it is `intakt/`, the one constant `CleanupPolicy::INTAKT_PREFIX`. Nothing in Intakt names its branches yet, so the code that will name them must use that constant.
- The design's "release branches" are the names above; the design's `keep-branches` list of the project file is the key `keep_branches` of the owner's config file, spelled like its other keys.
- The design's warning is "a dashboard chore and one notice". The dashboard has no chores and the `Notifier` contract has no implementation yet, so the warning list is the stored table, the command's output and the run's notice-level log record.
- The design's deletion right after a merge and the forge's auto-delete at setup belong to the merge path, which is not built; this weekly job deletes a merged branch a week after warning about it.

## Tests

`tests/Core/BranchCleanup/Domain/BranchRulesTest.php` (each filter and rule), `tests/Core/BranchCleanup/UseCase/CleanUpBranches/ProjectCleanupTest.php` (each rule over a temporary repository on the local forge with a clock the test moves and the warnings on SQLite: the warning a week ahead, no deletion before it or six days after it, a moved branch warned again, both claims, the prefix and its opt-in, the default and a release branch, merged, closed and `keep`, the restore, the lock), `CleanUpBranchesHandlerTest.php` beside it (a failing project, both commands, the schedule), `tests/Core/Git/UseCase/ArchiveBranch/MirrorBranchArchiveTest.php` (the archive ref, its survival of a mirror update, the newest archive, never overwritten, the claim check), `tests/Plugin/GitHubForge/GitHubForgeBranchesTest.php` (recorded GitHub answers) and `tests/Plugin/LocalGitForge/Infrastructure/LocalForgeBranchesTest.php`.
