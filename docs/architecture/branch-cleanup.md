## Branch cleanup

Branch cleanup ([design](../design/05-architecture/08-merge.md#branch-cleanup), plan task T30, #24) deletes the branches Intakt no longer needs. So far the forge side exists (the `ForgeBranches` contract, its local implementation and its GitHub implementation), the cleanup rules (a pure rule chain that decides on facts it is given) the archive of deleted branch heads, and the restore of a branch by pushing its archived commit back to the forge. No command, job or rule calls the forge or the archive yet, and nothing in the container builds a forge implementation; the restore command and its use case, and the weekly run that applies the verdicts, come in later parts of #24.

### In short

`ForgeBranches` lists a repository's branches, reads each branch's pull request, tells whether a person committed to it, and deletes it only at the head it was listed with. A read that fails throws, so a failure never reads as "no pull request" or "no commit by a person". The local forge runs git and deletes with `update-ref` at the expected head while holding every worktree's locks. The GitHub forge reads every page of the REST answers and checks each field; GitHub's delete takes no expected head, so it reads the branch right before deleting and refuses one that moved, accepting the short window between the two. A restore on either forge git-pushes the archived commit, never makes a ref from a commit id, and refuses a branch that exists.

### Cleanup rules

`Core/BranchCleanup/Domain/BranchRules::verdictOf(BranchFacts, DateTimeImmutable $now, ?BranchWarning)` returns a `Verdict`. It does no I/O and reads no clock: the facts and the moment are passed in. The rules are a chain of steps joined with the pipe operator; each step passes a `Judgement` on, and the first step that decides wins, so a later rule never overrules an earlier one.

`BranchFacts` holds the `ForgeBranch`, its `BranchChange` (or `null`), the time of the last commit, when the pull request was closed, and whether a person committed to the branch (`null` when that check could not answer).

In order:

1. **Out of bounds:** a branch without Intakt's prefix (`intakt/`) is `out-of-bounds`, unless the project sets `clean_other_branches: true`.
2. **Kept:** a branch whose `ForgeBranch::isDeletable()` is false (the default branch, a protected, a release or a symbolic branch) is `keep`, opt-in or not, so the rules never call deletable a branch the forge refuses to delete.
3. **Merged pull request:** `deletable` at once, with no warning.
4. **Closed, unmerged pull request:** `keep` for 30 days after the close, and always with the `keep` label; after that the branch is due (see below).
5. **Open pull request:** `keep`.
6. **Claimed branch with no pull request:** never due. It is `stale` after 30 days without commits and `keep` before. A branch is claimed when a person committed to it, when its name is in the project's `keep_branches`, or when the claim check could not answer (it fails closed). `keep_branches` applies only here: it claims no branch that has a pull request, so a merged or closed one is still handled by rules 3 and 4.
7. **Unclaimed branch with no pull request:** due 60 days after its last commit, `stale` from 30 days, `keep` before.

A due branch is `warn` until the week's warning has passed. `BranchWarning` names the branch, the head it warned about and the moment; `allowsDeletionAt(branch, head, now)` is true only when the branch and the head are the same (sibling branches often share a head, so one branch's warning never counts for another) and a full 7 x 24 hours have passed since the warning, never rounded to whole days. A moved head needs a new warning. The 30 and 60 days are counted from the moment given (`P30D`, `P60D`).

The project file takes two keys per project, read by `BranchCleanupConfiguration` and `CleanupPolicies` into the `intakt.branch_cleanup.projects` parameter: `clean_other_branches` (boolean, default false) and `keep_branches` (a list of branch names, default empty). Each may also be spelled with hyphens (`clean-other-branches`, `keep-branches`, as the design writes it); `config/packages/ai.php` takes both spellings out of the project before the AI projects tree sees it. A project that sets neither key gets the defaults from `CleanupPolicies::of()`.

The archive keeps every deleted branch head in a bare repository of its own on the data volume ([Archive](#archive)), and a delete archives first.

### The contract

`Plugin/Forge/Contract/ForgeBranches` is bound to one repository, like `Forge`, and has five operations:

- `branches()` returns every branch as a `ForgeBranch`: its `name` (without `refs/heads/`), its `head` (`CommitId`), and whether it `isDefault`, `isProtected` or `isSymbolic` (a ref that names another branch). `isRelease()` is true for a branch named `release` or `releases`, or starting with `release/`, `releases/`, `release-` or `releases-` (`ForgeBranch::isReleaseName()`), so both forges report release branches the same way. `isDeletable()` is false for a default, protected, release or symbolic branch, so the rules can never pick one.
- `changeOf(ForgeBranch)` returns the branch's pull request as a `BranchChange`: its `state` (`BranchChangeState`: `open`, `merged` or `closed-unmerged`) and its `labels` (`hasLabel('keep')`), or `null` when the branch has none (on GitHub also when no pull request holds its newest commits, see below).
- `hasCommitByAPerson(ForgeBranch, intaktIdentity)` is true when a commit on the branch that the default branch does not hold has an author or a committer other than Intakt's identity, which is what claims a branch with no pull request.
- `delete(ForgeBranch)` deletes the branch only at the head the `ForgeBranch` carries, so a branch that moved since the listing is kept.
- `restore(BranchRestore)` pushes an archived head back as the branch; `BranchRestore` holds the project (which names the archive), the branch name and the head (`CommitId`). See [Restore](#restore).

A read that fails or cannot answer throws `ForgeReadFailed`; it never counts as "no pull request" or "no commit by a person". A delete or a restore that is refused or fails throws `ForgeWriteFailed`. Calls into the contract use no named arguments.

Release names are matched with their letter case: `Release/1.0` is not a release name, and it is deletable unless something else keeps it.

### The local forge

`Plugin/LocalGitForge/Infrastructure/LocalForgeBranches` implements it for a `LocalRepository` (path and base branch). It runs git as `LocalGitForge` does: through `HardenedGit`, with an argument list and a 60-second timeout; a NUL byte in an argument, a git that does not start and a repository whose `info/attributes` exists are a `ForgeReadFailed`, and in a restore (`LocalBranchRestore`, which runs git the same way) a `ForgeWriteFailed` (see [Forge contract](forge-contract.md) and [Requirements](requirements.md#the-hardened-git-call)).

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
- **Pages:** every listing asks for 100 entries a page and reads the next page while the answer's `Link` header names a `next` link, read with `NextLinks` (RFC 8288); an unreadable `Link` header, a `rel` or `anchor` parameter without a value or a `rel` whose value names no relation type (`rel=""`) among them, is a `ForgeReadFailed`. The page number is counted by the forge, never taken from the link. More than 100 pages is a `ForgeReadFailed`, never a shorter listing.
- **Listing:** `GET /repos/<owner>/<repo>` names the `default_branch`, and `GET /repos/<owner>/<repo>/branches` lists each branch's `name`, `commit.sha` and `protected` flag. The branch named as the default is `isDefault`; release branches are told by their name, as on every forge. GitHub has no symbolic branches, so none is `isSymbolic`.
- **Pull request:** `GET /repos/<owner>/<repo>/pulls?state=all&head=<owner>:<branch>&sort=created&direction=desc`, every page, so an open or `keep`-labelled pull request on a later page is never missed. An open pull request decides first (`open`); otherwise the newest whose `head.sha` is the branch's head decides (`merged` when `merged_at` is a moment in GitHub's form `YYYY-MM-DDTHH:MM:SSZ`, `closed-unmerged` when it is `null`). When the branch has pull requests but none of them is open and none is at its current head, the branch reads as one without a pull request (`null`), so a person's new commit claims it, unless one of its older pull requests carries `keep`, which still keeps it (`closed-unmerged` with the labels). A branch without any pull request has none (`null`) too. The labels are those of every pull request of the branch, so a `keep` on an older one still counts. A pull request without `state`, `merged_at`, `labels` or a valid `head.sha`, and a closed one whose `merged_at` is neither `null` nor such a moment (an empty string, a day the month lacks or a trailing NUL byte too), is a `ForgeReadFailed`.
- **Commit by a person:** the default branch's head is read first (`GET /repos/<owner>/<repo>/branches/<default>`), and `GET /repos/<owner>/<repo>/compare/<default head>...<branch head>` lists the commits on the branch that the default branch does not hold, every page. Comparing with a commit and not the default branch's name keeps a default branch that advances between pages from shifting commits. Every page's `total_commits` must equal the number of commits read, and every commit must carry a valid `sha` that no other listed commit repeats, or the read is a `ForgeReadFailed`, so a repeated commit cannot make up the total while another stays unread. Each commit's `commit.author.email` and `commit.committer.email` are compared with Intakt's identity case-insensitively (`mb_strtolower`); an empty identity or email is a `ForgeReadFailed`.
- **Delete:** see [Delete on the GitHub forge](#delete-on-the-github-forge).

### Delete on the GitHub forge

A branch whose `isDeletable()` is false is refused before any call. Then the forge reads `GET /repos/<owner>/<repo>` and `GET /repos/<owner>/<repo>/branches/<name>` once more, and refuses the branch when it is the default branch now, when GitHub answers for another name (it redirects a renamed branch to its new name), when it is protected now, or when its head is no longer the head the `ForgeBranch` carries. Only then does `DELETE /repos/<owner>/<repo>/git/refs/heads/<name>` delete it. The delete is sent once (`max_retries: 0`): the client's automatic retry of a dropped connection or an answer such as `500` or `503` (the `retry_failed` setting of `github.client`) is turned off for it, because a retry would delete without reading the head again. A refusal, a failed read before the delete, and a delete that GitHub refuses or that does not reach it throw `ForgeWriteFailed`. A rate-limited call, one of those reads or the delete itself, throws `ForgeRateLimited` instead, as every GitHub call does. A read that fails before the delete means nothing is deleted. A delete whose connection drops leaves the outcome unknown, because GitHub may have deleted the branch before the answer was lost; the next listing shows which.

**Accepted window.** GitHub's REST ref deletion takes no expected head. GraphQL's `updateRefs` mutation names the expected old commit, but it is not used, because this change could not prove it against an installation's permissions. So a push that lands between the final read and the delete is deleted with the branch. That window is short and accepted, because cleanup candidates have been idle for weeks (owner decision of 2026-11-24 on #24).

### Archive

`Core/Git/Contract/BranchArchive` keeps a branch head; `UseCase/ArchiveBranch/MirrorBranchArchive` implements it. Each project has a bare repository at `var/data/branch-archive/<project>.git` (container parameter `intakt.git.branch_archive_directory`, `GitServices::BRANCH_ARCHIVE_DIRECTORY`), under `var/data` because that is where the image keeps its volume. It is not in `var/mirrors/`: the mirror is a cache that is cloned again when lost, so archive refs there would vanish at a container replacement. The project name follows the mirror's folder rule (`Domain/Mirrors`), so a name that would leave the directory is refused.

`archive(MirroredHead)` takes the project, the branch and its head commit, under the lock `git-branch-archive-<project>`:

1. Creates the bare repository when it is missing, in the mirror's object format.
2. Copies the commit and its objects from the mirror with a local fetch by id (`git fetch --no-tags --no-write-fetch-head -- <mirror> <commit>`, the `file` protocol allowed for this call only). The archive has no remote and writes no ref from the fetch.
3. Writes `refs/intakt/archive/<UTC moment>/<branch>` (moment as `YYYYMMDDTHHMMSSZ`, from `ClockInterface`, counted up on a clash as below) with `update-ref <ref> <commit> ''`, which creates the ref only: an existing archive ref is never moved.
4. Reads the ref back and returns an `ArchivedBranch`.

Before the write, the id must name a commit in the archive (`<id>^{commit}`), so a tree or blob is refused and no ref is left. When the name is taken by another commit (two heads of one branch in the same second), the moment gets a count: `<moment>-2/<branch>`, `-3`, and so on; the same commit again keeps its ref.

Any failure (no mirror, a commit the mirror lacks, an id that is not a commit, a mirror `HardenedGit` refuses because of its `info/attributes`, a git error) is a `BranchNotArchived`.

The archive belongs to Intakt alone: it is never cloned or fetched from a remote and its archive refs are never pushed (a [restore](#restore) pushes one archived commit to a branch, never a `refs/intakt/archive/*` ref), so a remote ref shaped like `refs/intakt/archive/*` is never an archive. `DeleteBranchAfterArchive` is the delete path: it archives, and only after that returns does it call `ForgeBranches::delete()`; when archiving fails, the forge is not called.

### Restore

A restore pushes the archived commit and its objects to the forge branch with git (owner decision 3 of 2026-11-24 on #24). It never makes a ref from a commit id, neither with `update-ref` nor with GitHub's ref API, because the forge may already have collected a commit that nothing referenced. It never overwrites a branch that exists.

**The push.** `Core/Git/Contract/ArchivedHeadPush::push(ArchivedHead, remote, ?RemoteCredential)` is implemented by `UseCase/PushBranch/PushArchivedHead`. It runs every git call through `BranchGit`, as the [branch push](branch-push.md) does, but signs nothing, merges nothing and opens no pull request. In order:

1. The archive is `var/data/branch-archive/<project>.git` (the archive's folder rule, `GitServices::BRANCH_ARCHIVES`). The branch name must pass `git check-ref-format refs/heads/<name>`, `git cat-file -t <head>` in the archive must say `commit`, and `git rev-parse --verify --end-of-options <head>^{commit}` must print the head itself (`<head> is no full commit id of the archive` otherwise), because a SHA-256 archive takes a 40-digit id as an abbreviation.
2. It takes the build push's lock of the same project and branch (`PushLockName`), without waiting, so a build push and a restore of one branch never run together. A busy lock is "another push of this branch is running". The lock is released when the restore ends, landed or failed.
3. It refuses a remote the archive's config rewrites, with the same check as the build push (`BranchGit::refuseRewrittenRemote()`).
4. It reads the branch with `ls-remote` (`BranchGit::remoteTip()`) and refuses one that exists. `ls-remote` does not list a symbolic branch whose target is missing, so each forge checks for that itself before the push (below).
5. It runs `git push --porcelain --force-with-lease=refs/heads/<name>: -- <remote> <head>:refs/heads/<name>`. The empty lease lets the push only create the branch, so a branch made after step 4 is refused even when the head would fast-forward it. A branch another writer made at the head itself is "up to date" to git, which pushes nothing and exits with 0, so the push's porcelain status must be `*` (`[new branch]`); anything else is `the push did not create the branch <name>, so another writer made it during the restore`.
6. It reads the branch again and requires it at the head.

Every refusal and failure is `BranchNotPushed`, whose reason never names the remote. A credential (`RemoteCredential`, a user and a password kept in a `SensitiveParameterValue`) reaches git only as an `http.extraHeader` entry in its environment (`Authorization: Basic ...`), never on its command line or in the address, and the remote, git's arguments and processes are `#[SensitiveParameter]` on every call of the restore, so a trace shows neither.

**On the forges.** Each forge calls the push with its own remote. A refusal or failure, the push's included, is a `ForgeWriteFailed` starting `The branch "<name>" was not restored:`.

- `LocalForgeBranches` hands the restore to `LocalBranchRestore`, which pushes to the repository's absolute common directory (`rev-parse --path-format=absolute --git-common-dir`), because git would resolve a relative path from the archive. Before the push it refuses a branch for which `git show-ref --exists refs/heads/<name>` finds a ref, a symbolic branch whose target is missing included, because a push to that branch would create its target; a ref that cannot be read is refused alike (`it exists or cannot be read, and a restore never overwrites a branch.`).
- `GitHubForgeBranches` first reads `GET /repos/<owner>/<repo>/branches/<name>`: `200` is a branch that exists (also a renamed branch GitHub redirects to its new name) and is refused (`it exists, and a restore never overwrites a branch.`) before any push, `404` a missing one, and any other status a refusal. It pushes to `https://github.com/<owner>/<repo>.git` with the credential `x-access-token` and the App's installation token. A rate-limited read throws `ForgeRateLimited`.

**Limits.** On the local forge, a symbolic branch whose target is missing that another writer makes between the `show-ref --exists` check and the push is not seen, and the push creates its target.

### Tests

The archive tests are in `tests/Core/Git/UseCase/ArchiveBranch/MirrorBranchArchiveTest.php`, against temporary repositories. The restore push is tested in `tests/Core/Git/UseCase/PushBranch/PushArchivedHeadTest.php`, against temporary repositories where git has collected the deleted branch's commit, with a forced order for a branch made between the check and the push (at another commit and at the head itself), a SHA-256 archive, and git's command line and environment recorded for the credential. The restore on the forges is tested in `LocalForgeBranchesTest` (a real archive, delete and collection, a dangling symbolic branch and a relative path) and `GitHubForgeBranchesTest` (recorded answers, and the real push into a temporary repository without the commit, with GitHub's address swapped for it by `GitHubAddressOnDisk`).

The tests are in `tests/Plugin/GitHubForge/GitHubForgeBranchesTest.php` (against recorded GitHub answers), `tests/Plugin/LocalGitForge/Infrastructure/LocalForgeBranchesTest.php` (against a temporary repository), `tests/Plugin/LocalGitForge/Infrastructure/WorktreeLocksTest.php` `tests/Plugin/Forge/Contract/ForgeBranchTest.php`, and for the rules `tests/Core/BranchCleanup/Domain/BranchRulesTest.php`, `BranchWarningTest.php`, `tests/Core/BranchCleanup/Infrastructure/BranchCleanupConfigurationTest.php` and `CleanupPoliciesTest.php`, and for the project-file wiring `tests/Application/ProjectTiersTest.php`.
