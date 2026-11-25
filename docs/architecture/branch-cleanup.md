## Branch cleanup

Branch cleanup ([design](../design/05-architecture/08-merge.md#branch-cleanup), plan task T30, #24) deletes the branches Intakt no longer needs. So far only the forge side exists: the `ForgeBranches` contract, its local implementation and its GitHub implementation. No command, job or rule calls it yet, and nothing in the container builds either implementation; the archive refs and restore, the cleanup rules and the weekly run come in later parts of #24.

### In short

`ForgeBranches` lists a repository's branches, reads each branch's pull request, tells whether a person committed to it, and deletes it only at the head it was listed with. A read that fails throws, so a failure never reads as "no pull request" or "no commit by a person". The local forge runs git and deletes with `update-ref` at the expected head while holding every worktree's locks. The GitHub forge reads every page of the REST answers and checks each field; GitHub's delete takes no expected head, so it reads the branch right before deleting and refuses one that moved, accepting the short window between the two.

### The contract

`Plugin/Forge/Contract/ForgeBranches` is bound to one repository, like `Forge`, and has four operations:

- `branches()` returns every branch as a `ForgeBranch`: its `name` (without `refs/heads/`), its `head` (`CommitId`), and whether it `isDefault`, `isProtected` or `isSymbolic` (a ref that names another branch). `isRelease()` is true for a branch named `release` or `releases`, or starting with `release/`, `releases/`, `release-` or `releases-` (`ForgeBranch::isReleaseName()`), so both forges report release branches the same way. `isDeletable()` is false for a default, protected, release or symbolic branch, so the rules can never pick one.
- `changeOf(ForgeBranch)` returns the branch's pull request as a `BranchChange`: its `state` (`BranchChangeState`: `open`, `merged` or `closed-unmerged`) and its `labels` (`hasLabel('keep')`), or `null` when the branch has none.
- `hasCommitByAPerson(ForgeBranch, intaktIdentity)` is true when a commit on the branch that the default branch does not hold has an author or a committer other than Intakt's identity, which is what claims a branch with no pull request.
- `delete(ForgeBranch)` deletes the branch only at the head the `ForgeBranch` carries, so a branch that moved since the listing is kept.

A read that fails or cannot answer throws `ForgeReadFailed`; it never counts as "no pull request" or "no commit by a person". A delete that is refused or fails throws `ForgeWriteFailed`. Calls into the contract use no named arguments.

Release names are matched with their letter case: `Release/1.0` is not a release name, and it is deletable unless something else keeps it.

### The local forge

`Plugin/LocalGitForge/Infrastructure/LocalForgeBranches` implements it for a `LocalRepository` (path and base branch). It runs git as `LocalGitForge` does: through `HardenedGit`, with an argument list and a 60-second timeout; a NUL byte in an argument, a git that does not start and a repository whose `info/attributes` exists are a `ForgeReadFailed` (see [Forge contract](forge-contract.md) and [Requirements](requirements.md#the-hardened-git-call)).

The repository must keep its refs in files (`git rev-parse --show-ref-format` prints `files`); a reftable repository is a `ForgeReadFailed` for the listing and the delete, because both rely on the ref files.

- **Listing:** `git for-each-ref --format=%(refname)%00%(objectname)%00%(objecttype)%00%(symref) refs/heads/`. The base branch is the default branch. A local repository protects nothing, so no branch is protected. A branch with a `symref` is symbolic. A line that is not a branch at a commit is a `ForgeReadFailed`. `for-each-ref` silently leaves out a symbolic branch that dangles and a ref file that does not parse, so every loose file under the common directory's `refs/heads/` (`git rev-parse --path-format=absolute --git-common-dir`) must be in the listing, or the listing is a `ForgeReadFailed`; a `.lock` file there is git writing a branch and is skipped.
- **Base branch:** the changes and the person check read the base branch's head with `git show-ref --verify --hash refs/heads/<base>`, the exact branch, so a tag named `refs/heads/<base>` never stands in for a missing base branch (a missing one is a `ForgeReadFailed`). A shallow repository (`git rev-parse --is-shallow-repository` prints `true`) is a `ForgeReadFailed` for both reads, because a shallow boundary hides commits and ancestry.
- **Pull request:** as in the local git forge, a branch against the base branch is the pull request. The base branch itself has none (`null`). Another branch's change is `merged` when the base branch holds its head (`git merge-base --is-ancestor <head> <base head>`, exit 0) and `open` otherwise (exit 1); any other exit is a `ForgeReadFailed`. A local change is never `closed-unmerged` and has no labels. So a branch with no commits of its own, cut from the base branch, reads as `merged`: deleting it loses no commit.
- **Commit by a person:** `git log -z --format=%ae%x00%ce <head> --not <base head> --` lists the author and committer email of every commit on the branch that the base branch does not hold. Emails are compared with Intakt's identity case-insensitively (`mb_strtolower`). An empty identity, and a commit with an empty author or committer email, are a `ForgeReadFailed`, because whose the commit is cannot be told.
- **Delete:** see [Delete on the local forge](#delete-on-the-local-forge).

### Delete on the local forge

Every guard is read again at delete time, because the listing may be old and a caller may build a `ForgeBranch` itself. The branch is refused when `isDeletable()` is false or when its name is the base branch or a release name. Every refusal and failure is a `ForgeWriteFailed` naming the branch.

**Locks.** The delete (through `Plugin/LocalGitForge/Infrastructure/WorktreeLocks`) creates `index.lock` and `HEAD.lock`, exclusively as git does and in that order, in the common directory and in every linked worktree's directory (`<common dir>/worktrees/*/`). `git switch` and `git checkout` take `index.lock` before they change a file and write `HEAD` through `HEAD.lock`, and give up while either exists. So no checkout can name the branch until the delete is done, and a checkout that is held off has not touched its working tree.

A lock that already exists is a refusal (a checkout or another git command is under way): the locks already taken are removed, and the delete never waits.

**Checks.** Holding the locks, the delete refuses the branch when `git symbolic-ref --quiet refs/heads/<name>` says it is symbolic now, and when any worktree's `HEAD` names it. It reads `git worktree list --porcelain -z`, and the `HEAD` in the common directory (`git --git-dir=<common dir> --bare symbolic-ref --quiet HEAD` through `HardenedGit::callInBareRepository()`). A bare repository lists that `HEAD` without a branch line, also when the forge is opened through one of its linked worktrees.

A rebase or a bisect detaches a worktree's `HEAD`, so the listing names no branch while finishing or aborting it writes the branch back (git's own `branch -d` refuses such a branch too). The delete therefore also refuses the branch that `rebase-merge/head-name`, `rebase-apply/head-name` or `BISECT_START` names, in the common directory and in each `worktrees/<id>` directory under it (`RebasesAndBisects`, over the same directories `WorktreeLocks` locks, from `WorktreeGitDirectories`). A `rebase-apply` directory is a rebase when it holds a `rebasing` file and `git am` when it holds only an `applying` file; `git am` keeps `HEAD` on its branch, so it names none here. A `head-name` of `detached HEAD` names no branch. A refusal (a `ForgeReadFailed`) is any state that cannot tell its branch: a rebase whose `head-name` is missing, empty or neither a `refs/heads/` name nor `detached HEAD` (such as a short branch name), a rebase or bisect whose branch name fails `git check-ref-format` (such as `refs/heads/topic..old`, which git never writes and no listed branch matches), a `rebase-apply` directory with neither a `rebasing` nor an `applying` file, or a state file it cannot read.

A worktree the listing shows as `locked initializing` is one `git worktree add` has not finished, so the check cannot answer and the delete is refused.

**Delete.** `git update-ref -d --no-deref refs/heads/<name> <expected head>` deletes the branch; git refuses when it is no longer at that head. `--no-deref` means a delete never goes through a symbolic ref: a branch that became symbolic after the check loses only its own ref, never the branch it names.

**Put-back.** The locks are removed, and the worktree check is read once more, because `git worktree add` takes none of the locks. When a worktree names the branch now, or when this second check fails or cannot answer, the branch is put back with `git update-ref --no-deref refs/heads/<name> <expected head> ''`. That creates the branch only while it is still missing. Where branch reflogs are enabled, a branch that is put back starts a new reflog.

When the put-back succeeds, the delete reports a refusal: `The branch "<name>" was not deleted: ..., so it was put back at its head.`

When the put-back fails, the delete reads the branch once more with `git show-ref --verify --hash refs/heads/<name>` (and `git show-ref --exists` to tell a missing branch from a failed read). The `ForgeWriteFailed` then says, with git's error and the reason for the put-back, and never that the branch was not deleted:

- the branch is missing: `The branch "<name>" is deleted at <expected head> and could not be put back`; that head restores it by hand;
- another writer made it again at a commit (`git cat-file -t` says `commit`): `The branch "<name>" was deleted at <expected head> and exists again at <commit>`;
- the read fails, or the branch was made again as a symbolic ref to an object that is no commit: `The branch "<name>" was deleted at <expected head> and its current state could not be read`.

**Limits.** A `git worktree add` that resolved the branch before the delete and creates its worktree directory only after the second check is seen by neither check, and its `HEAD` then names the deleted branch. A second writer that holds the branch's ref lock during the put-back makes it fail in that same window; the delete then reports the branch as deleted with the head that restores it.

A process killed while it holds the locks leaves the `index.lock` and `HEAD.lock` files behind. Git then refuses to check out or change `HEAD` until they are removed, as after any crashed git command.

### The GitHub forge

`Plugin/GitHubForge/Infrastructure/GitHubForgeBranches` implements it for one repository (a `RepositoryAddress`). `GitHubBranchCalls` makes the REST calls as the installation (through `GitHubCalls`, with the checked installation token) and checks each answer's shape; `GitHubForgeBranches` maps the answers onto the contract.

- **Answers:** only `200` counts for a read (`204` for the delete); any other status, an answer that is not JSON, a missing or mistyped field and a head that is not a full commit id are a `ForgeReadFailed`. A JSON object whose keys read as a list (`{}`, or keys `"0"`, `"1"` and so on) is read as no value, so it never passes for a list of branches, pull requests, commits or labels. A rate-limited call throws `ForgeRateLimited`, as every GitHub call does. A branch name with an empty part or a part that starts with `.` is refused before any call names it (for the delete as a `ForgeWriteFailed`), and every part is URL-encoded.
- **Pages:** every listing asks for 100 entries a page and reads the next page while the answer's `Link` header names a `next` link, read with `NextLinks` (RFC 8288); an unreadable `Link` header is a `ForgeReadFailed`. The page number is counted by the forge, never taken from the link. More than 100 pages is a `ForgeReadFailed`, never a shorter listing.
- **Listing:** `GET /repos/<owner>/<repo>` names the `default_branch`, and `GET /repos/<owner>/<repo>/branches` lists each branch's `name`, `commit.sha` and `protected` flag. The branch named as the default is `isDefault`; release branches are told by their name, as on every forge. GitHub has no symbolic branches, so none is `isSymbolic`.
- **Pull request:** `GET /repos/<owner>/<repo>/pulls?state=all&head=<owner>:<branch>&sort=created&direction=desc`, every page, so an open or `keep`-labelled pull request on a later page is never missed. An open pull request decides first (`open`); otherwise the newest whose `head.sha` is the branch's head decides (`merged` when `merged_at` is set, `closed-unmerged` when it is `null`), because a pull request closed before later commits says nothing about them. With neither, the branch has none (`null`). The labels are those of every pull request of the branch, so a `keep` on an older one still counts. A pull request without `state`, `merged_at`, `labels` or a valid `head.sha` is a `ForgeReadFailed`.
- **Commit by a person:** the default branch's head is read first (`GET /repos/<owner>/<repo>/branches/<default>`), and `GET /repos/<owner>/<repo>/compare/<default head>...<branch head>` lists the commits on the branch that the default branch does not hold, every page. Comparing with a commit and not the default branch's name keeps a default branch that advances between pages from shifting commits. Every page's `total_commits` must equal the number of commits read, or the read is a `ForgeReadFailed`. Each commit's `commit.author.email` and `commit.committer.email` are compared with Intakt's identity case-insensitively (`mb_strtolower`); an empty identity or email is a `ForgeReadFailed`.
- **Delete:** see [Delete on the GitHub forge](#delete-on-the-github-forge).

### Delete on the GitHub forge

A branch whose `isDeletable()` is false is refused before any call. Then the forge reads `GET /repos/<owner>/<repo>` and `GET /repos/<owner>/<repo>/branches/<name>` once more, and refuses the branch when it is the default branch now, when GitHub answers for another name (it redirects a renamed branch to its new name), when it is protected now, or when its head is no longer the head the `ForgeBranch` carries. Only then does `DELETE /repos/<owner>/<repo>/git/refs/heads/<name>` delete it. Every refusal and failure is a `ForgeWriteFailed`, and a read that fails before the delete means nothing is deleted.

**Accepted window.** GitHub's REST ref deletion takes no expected head. GraphQL's `updateRefs` mutation names the expected old commit, but it is not used, because this change could not prove it against an installation's permissions. So a push that lands between the final read and the delete is deleted with the branch. That window is short and accepted, because cleanup candidates have been idle for weeks (owner decision of 2026-11-24 on #24).

### Tests

The tests are in `tests/Plugin/GitHubForge/GitHubForgeBranchesTest.php` (against recorded GitHub answers), `tests/Plugin/LocalGitForge/Infrastructure/LocalForgeBranchesTest.php` (against a temporary repository), `tests/Plugin/LocalGitForge/Infrastructure/WorktreeLocksTest.php` and `tests/Plugin/Forge/Contract/ForgeBranchTest.php`.
