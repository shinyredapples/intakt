# Branch cleanup: the forge side

Branch cleanup removes branches nobody needs any more. This page describes the part that reads and deletes branches on a forge. Nothing calls it yet: the archive refs and restore, the cleanup rules, and the weekly run and its command come in later parts and extend this page.

## The contract

`Plugin/Forge/Contract/ForgeBranches` is a tagged interface. Each project lives on one forge, so callers ask `servesProject()` of every implementation. The operations:

- `branchesOf(project)` lists `ForgeBranch` values: name, head (`CommitId`), and the flags `isDefault`, `isProtected` and `isRelease`. The rules of a later part never pick a branch with any of the three flags. `ForgeBranch::isReleaseName()` is the one release rule for every forge: `release`, or a name starting with `release/` or `release-`, in any case.
- `changeOf(project, branch)` returns the `BranchChange` of the pull request opened for the branch's current head, or null when there is none. It holds the `BranchChangeState` (`Open`, `Merged`, `ClosedUnmerged`) and when the pull request closed, taken from an open pull request of that head if there is one, else from the newest. Its labels (such as `keep`) are those of every pull request of that head, so a `keep` on an older one still counts. A pull request made for an older head says nothing about later commits, so it is ignored.
- `hasCommitByPerson(project, branch)` tells whether the branch holds a commit that is not Intakt's, among the commits not on the default branch. A claim of a branch needs this.
- `deleteBranch(project, branch)` deletes the branch only at the head the `ForgeBranch` carries.

A read that fails or cannot answer throws `ForgeReadFailed` (or `ForgeRateLimited`), and a refused delete throws `ForgeWriteFailed`. A failure never counts as "no pull request" or "no commit by a person".

## GitHub (`GitHubForgeBranches`)

- `GitHubBranchCalls` holds the reads and the delete and checks every answer; `GitHubForgeBranches` maps them. Every answer is validated field by field. A missing `protected` flag, labels, head or date fails the read, and so does a head that is not a commit id. A JSON object whose keys read as a list (`{}`, or keys `"0"`, `"1"` and so on) is read as no value, so it fails the read in place of a list of pull requests, commits or labels instead of passing for one.
- The pull-request lookup (`GET /pulls?state=all&head=<owner>:<branch>`) and the commit comparison follow every page, with no page limit, using `NextLinks` on the `Link` header, so an open or `keep`-labelled pull request on a later page is never missed. A `next` link must name exactly the following page (`page=<n+1>`); one that points back, repeats or skips a page fails the read, so a looping header can neither page forever nor end in an empty page read as no pull request.
- `hasCommitByPerson` reads the default branch's head commit first and compares that commit with the branch head, so a default branch that advances while the pages are read cannot shift commits onto a page already read. A commit is Intakt's only when its GitHub author is this App's own bot account (`SteeringIdentities::isBot()` and `isOwn()`); a commit without a GitHub account, or by any other account, is a person's.
- `deleteBranch` reads the branch head right before the delete and refuses when it differs from the expected head. GitHub's REST ref deletion takes no expected head, and the GraphQL `updateRefs` mutation was not used because this build cannot prove it against the installation's permissions. The short window between that read and the delete is accepted: cleanup candidates have been idle for weeks. A head that cannot be read counts as moved and nothing is deleted.

## Local repository (`LocalForgeBranches`)

- Serves the projects without a GitHub repository. A local repository has no pull requests and no protection, so `changeOf` is always null and no branch is protected. The default branch is the one `HEAD` names.
- Symbolic branches (an alias of `main`, for example) are left out of the listing.
- `deleteBranch` first creates `HEAD.lock` for the main worktree and every linked one (git's own lock, which a checkout takes to move `HEAD`) and holds them until the delete is done. A checkout cannot then slip in between the check and the delete and leave `HEAD` on a deleted branch; a lock that already exists means another git command is writing, and the delete is refused. A new worktree linked to the branch between the check and the delete is not covered, because `git worktree add` takes none of these locks. While holding the locks it refuses every branch a worktree has checked out (the main one and every linked one, from `git worktree list --porcelain`, compared by full ref name so a tag of the same name changes nothing), then runs `git update-ref -d --no-deref refs/heads/<name> <expected head>`. `--no-deref` means a symbolic branch handed in anyway deletes only the alias, never its target, and git refuses the delete when the branch moved.
- `hasCommitByPerson` runs `git log --format=<%ae> <head> --not <default ref> --`. The closing `--` makes a default ref that does not resolve fail the read instead of being taken as a path. A commit is Intakt's when its author email is `LocalForgeBranches::INTAKT_EMAIL` (`intakt@localhost`); any other email, an empty one included, is a person's. A detached `HEAD` has no default to compare against, so the read fails.

Tests: `tests/Plugin/GitHubForge/GitHubForgeBranchesTest.php` (recorded responses) and `tests/Plugin/LocalGitForge/Infrastructure/LocalForgeBranchesTest.php` (a temporary repository).
