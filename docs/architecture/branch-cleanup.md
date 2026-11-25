## Branch cleanup

Branch cleanup ([design](../design/05-architecture/08-merge.md#branch-cleanup), plan task T30, #24) deletes the branches Intakt no longer needs. So far only the forge side exists: the `ForgeBranches` contract and its local implementation. No command, job or rule calls it yet; the GitHub implementation, the archive refs and restore, the cleanup rules and the weekly run come in later parts of #24.

### The contract

`Plugin/Forge/Contract/ForgeBranches` is bound to one repository, like `Forge`, and has four operations:

- `branches()` returns every branch as a `ForgeBranch`: its `name` (without `refs/heads/`), its `head` (`CommitId`), and whether it `isDefault`, `isProtected` or `isSymbolic` (a ref that names another branch). `isRelease()` is true for a branch named `release` or `releases`, or starting with `release/`, `releases/` or `release-` (`ForgeBranch::isReleaseName()`), so both forges report release branches the same way. `isDeletable()` is false for a default, protected, release or symbolic branch, so the rules can never pick one.
- `changeOf(ForgeBranch)` returns the branch's pull request as a `BranchChange`: its `state` (`BranchChangeState`: `open`, `merged` or `closed-unmerged`) and its `labels` (`hasLabel('keep')`), or `null` when the branch has none.
- `hasCommitByAPerson(ForgeBranch, intaktIdentity)` is true when a commit on the branch that the default branch does not hold has an author or a committer other than Intakt's identity, which is what claims a branch with no pull request.
- `delete(ForgeBranch)` deletes the branch only at the head the `ForgeBranch` carries, so a branch that moved since the listing is kept.

A read that fails or cannot answer throws `ForgeReadFailed`; it never counts as "no pull request" or "no commit by a person". A delete that is refused or fails throws `ForgeWriteFailed`. Calls into the contract use no named arguments.

### The local forge

`Plugin/LocalGitForge/Infrastructure/LocalForgeBranches` implements it for a `LocalRepository` (path and base branch). It runs git as `LocalGitForge` does: through `HardenedGit`, with an argument list and a 60-second timeout; a NUL byte in an argument, a git that does not start and a repository whose `info/attributes` exists are a `ForgeReadFailed` (see [Forge contract](forge-contract.md) and [Requirements](requirements.md#the-hardened-git-call)).

- **Listing:** `git for-each-ref --format=%(refname)%00%(objectname)%00%(objecttype)%00%(symref) refs/heads/`. The base branch is the default branch. A local repository protects nothing, so no branch is protected. A branch with a `symref` is symbolic. A line that is not a branch at a commit is a `ForgeReadFailed`.
- **Pull request:** as in the local git forge, a branch against the base branch is the pull request. The base branch itself has none (`null`). Another branch's change is `merged` when the base branch holds its head (`git merge-base --is-ancestor <head> refs/heads/<base>`, exit 0) and `open` otherwise (exit 1); any other exit is a `ForgeReadFailed`. A local change is never `closed-unmerged` and has no labels. So a branch with no commits of its own, cut from the base branch, reads as `merged`: deleting it loses no commit.
- **Commit by a person:** `git log -z --format=%ae%x00%ce <head> --not refs/heads/<base> --` lists the author and committer email of every commit on the branch that the base branch does not hold. Emails are compared with Intakt's identity case-insensitively (`mb_strtolower`). An empty identity, and a commit with an empty author or committer email, are a `ForgeReadFailed`, because whose the commit is cannot be told.
- **Delete:** every guard is read again at delete time, because the listing may be old and a caller may build a `ForgeBranch` itself. The branch is refused when `isDeletable()` is false, when its name is the base branch or a release name, when `git symbolic-ref --quiet refs/heads/<name>` says it is symbolic now, and when any worktree's `HEAD` names it (`git worktree list --porcelain -z`, the repository's own `HEAD` among them). Then `git update-ref -d --no-deref refs/heads/<name> <expected head>` deletes it; git refuses when the branch is no longer at that head. `--no-deref` means a delete never goes through a symbolic ref: a branch that became symbolic after the check loses only its own ref, never the branch it names. Every refusal and failure is a `ForgeWriteFailed` naming the branch.

The checks and the delete are separate git calls, so a branch checked out in the moment between the worktree check and `update-ref` is still deleted; git has no lock a caller could hold across both.

The tests are in `tests/Plugin/LocalGitForge/Infrastructure/LocalForgeBranchesTest.php` (against a temporary repository) and `tests/Plugin/Forge/Contract/ForgeBranchTest.php`.
