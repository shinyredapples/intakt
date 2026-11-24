# Branch cleanup: the forge side

Branch cleanup removes branches nobody needs any more. This page describes the part that reads and deletes branches on a forge. Nothing calls it yet: the archive refs and restore, the cleanup rules, and the weekly run and its command come in later parts and extend this page.

## The contract

`Plugin/Forge/Contract/ForgeBranches` is a tagged interface. Each project lives on one forge, so callers ask `servesProject()` of every implementation. The operations:

- `branchesOf(project)` lists `ForgeBranch` values: name, head (`CommitId`), and the flags `isDefault`, `isProtected` and `isRelease`. The rules of a later part never pick a branch with any of the three flags. `ForgeBranch::isReleaseName()` is the one release rule for every forge: `release`, or a name starting with `release/` or `release-`, in any case.
- `changeOf(project, branch)` returns the `BranchChange` of the pull request opened for the branch's current head, or null when there is none. It holds the `BranchChangeState` (`Open`, `Merged`, `ClosedUnmerged`), when the pull request closed, and its labels (such as `keep`). A pull request made for an older head says nothing about later commits, so it is ignored.
- `hasCommitByPerson(project, branch)` tells whether the branch holds a commit that is not Intakt's, among the commits not on the default branch. A claim of a branch needs this.
- `deleteBranch(project, branch)` deletes the branch only at the head the `ForgeBranch` carries.

A read that fails or cannot answer throws `ForgeReadFailed` (or `ForgeRateLimited`), and a refused delete throws `ForgeWriteFailed`. A failure never counts as "no pull request" or "no commit by a person".

## GitHub (`GitHubForgeBranches`)

- Every answer is validated field by field. A missing `protected` flag, labels, head or date fails the read.
- The pull-request lookup (`GET /pulls?state=all&head=<owner>:<branch>`) and the commit comparison follow every page, using `NextLinks` on the `Link` header, so an open or `keep`-labelled pull request on a later page is never missed. More than ten pages fails the read.
- `hasCommitByPerson` compares the default branch with the branch head. A commit is Intakt's only when its GitHub author is this App's own bot account (`SteeringIdentities::isBot()` and `isOwn()`); a commit without a GitHub account, or by any other account, is a person's.
- `deleteBranch` reads the branch head right before the delete and refuses when it differs from the expected head. GitHub's REST ref deletion takes no expected head, and the GraphQL `updateRefs` mutation was not used because this build cannot prove it against the installation's permissions. The short window between that read and the delete is accepted: cleanup candidates have been idle for weeks. A head that cannot be read counts as moved and nothing is deleted.

## Local repository (`LocalForgeBranches`)

- Serves the projects without a GitHub repository. A local repository has no pull requests and no protection, so `changeOf` is always null and no branch is protected. The default branch is the one `HEAD` names.
- Symbolic branches (an alias of `main`, for example) are left out of the listing.
- `deleteBranch` refuses the branch `HEAD` names at delete time, then runs `git update-ref -d --no-deref refs/heads/<name> <expected head>`. `--no-deref` means a symbolic branch handed in anyway deletes only the alias, never its target, and git refuses the delete when the branch moved.
- `hasCommitByPerson` runs `git log --format=%ae <head> --not refs/heads/<default>`. A commit is Intakt's when its author email is `LocalForgeBranches::INTAKT_EMAIL` (`intakt@localhost`); a detached `HEAD` has no default to compare against, so the read fails.

Tests: `tests/Plugin/GitHubForge/GitHubForgeBranchesTest.php` (recorded responses) and `tests/Plugin/LocalGitForge/Infrastructure/LocalForgeBranchesTest.php` (a temporary repository).
