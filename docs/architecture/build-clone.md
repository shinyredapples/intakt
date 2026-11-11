# Build clone

A build works in a fresh clone of the project made from its [bare mirror](mirror.md), never from the remote. The clone holds main and the task's branch and no other ref: no other branch, no tag, no remote and no remote-tracking ref, and no commit only another branch reaches.

The use case `MakeBuildClone::make(<project>, <branch>, <directory>)` in `src/Core/Git/UseCase/MakeBuildClone/` makes it:

1. The mirror is `var/mirrors/<project>.git` (`Intakt\Core\Git\Domain\Mirrors`, the one home of the project-name rule that `UpdateMirror` uses too). A project whose mirror has no `HEAD` file has no mirror yet, and the clone is refused.
2. Main is the branch the mirror's `HEAD` names (`git --git-dir=<mirror> symbolic-ref --quiet HEAD`), because the mirror's `HEAD` follows the remote's default branch. A mirror whose `HEAD` is detached, or names a ref outside `refs/heads/` (a tag, for example), has no main, and the clone is refused.
3. The branch must pass `git check-ref-format --branch <branch>`, and git's answer must be the name itself, because git expands a name such as `@{-1}` to another branch. A branch that is main itself is refused.
4. The mirror's object format is read (`rev-parse --show-object-format`), because a fetch from a SHA-256 mirror into a SHA-1 repository fails.
5. The folder is reserved: its parent folders are made, then the folder itself with a single `mkdir`, which fails when anything (a folder, a file or a link) is already at the path. A clone therefore never mixes with what a folder already holds, and of two calls for the same folder only the one whose `mkdir` succeeds goes on; the other is refused with `the folder already exists`.
6. `git init --initial-branch=<branch> --object-format=<format>` makes the clone in that folder, with the mirror's object format.
7. `git fetch --quiet --no-tags --no-write-fetch-head --update-head-ok -- <mirror> +refs/heads/<main>:refs/heads/<main>` fetches main. Init and fetch replace `git clone`, because a clone would add a remote, remote-tracking refs and tags. `--update-head-ok` lets the fetch write the task's branch, which is the branch `HEAD` names.
8. When the mirror holds the task's branch (`show-ref --verify --quiet refs/heads/<branch>`), it is fetched the same way; otherwise the branch starts at main (`update-ref`).
9. `git checkout --force --quiet` fills the work tree from the task's branch. It writes no `ORIG_HEAD`.

Every call is built by `HardenedGit`. The calls in the mirror carry `--git-dir=<mirror>` and unset `GIT_WORK_TREE`, as in `UpdateMirror`; `init` unsets it too, because git refuses a work tree without a git folder. The fetches allow the `file` transport only (`GIT_ALLOW_PROTOCOL=file`), because git reads a local path as that transport. The fetches are local, so they are not retried ([Retried network git calls](network-git.md) are for the remote).

A step that fails throws `BuildCloneNotMade` with git's message. When the folder was already reserved by this call, it is removed, so a clone that failed half-way is never taken for a build clone. A project name that is not a safe folder name, and a branch that is unsafe or main, throw `InvalidArgumentException`.

No console command and no build job (plan task T49) calls the use case yet. The `Source:` line of a task, which adds one verified commit as a read-only ref, is the third part of plan task T46 and not built yet.
