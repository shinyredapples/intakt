# Bare mirror and retried network git calls

Intakt keeps one bare mirror per project at `var/mirrors/<project>.git` (the container parameter `intakt.git.mirrors_directory`, `Intakt\Core\Git\Contract\GitServices::MIRRORS_DIRECTORY`). The mirror is a cache of the remote: if it is lost, the next update clones it again.

## Updating a mirror

`php bin/console intakt:mirror:update <project> <remote>` (`UpdateMirror` in `src/Core/Git/UseCase/UpdateMirror/`) fills a mirror with `git --git-dir=<mirror> fetch --prune --quiet -- <remote> +refs/*:refs/*`, then points the mirror's `HEAD` at the branch `git ls-remote --symref -- <remote> HEAD` names. The remote is named on every call, so a mirror never depends on a remote setting. `--git-dir` pins every call to the mirror, so git never looks upward into the install's own checkout.

- A mirror exists when the path holds `HEAD`, `objects/` and `refs/` and `git rev-parse --is-bare-repository` says `true`. It is fetched in place, so a branch the remote deleted disappears and a bare repository left without refs is filled.
- Anything else (no folder, an empty folder, a folder that is not a bare repository) gets a new mirror: `git init --bare` in a staging folder `var/mirrors/.<project>.git.<random>`, filled as above, then renamed to `<project>.git`. A clone that fails or dies half-way stays in its staging folder, which is removed, so an incomplete mirror is never published.
- The rename runs under the Symfony lock `git-mirror-<project>`, which checks the path again first: when another update published the mirror meanwhile, that mirror is kept, the staging folder is dropped, and the overtaken update then fetches into the published mirror once more (outside the lock, like every fetch), because the update that won may have fetched earlier and the mirror must be at least as fresh as the overtaken fetch. The fetches run outside the lock; git's own ref locks guard them.

A local git step that fails (`init`, `symbolic-ref`) or a rename that fails throws `MirrorNotUpdated`; the console command prints it and exits with 1.

The project name becomes a folder name, so a name must start with a letter or a digit, hold only letters, digits, `.`, `_` and `-`, and not contain `..`. Anything else is refused before git runs. The remote is passed after `--`, so it can never be read as an option. The remote is an argument because a project has no remote setting yet.

All calls are built by `HardenedGit`; `UpdateMirror` unsets `GIT_WORK_TREE` for them because the mirror is bare. The two network calls run through `NetworkGit`. `HardenedGit` allows no transport protocol and pins a work tree, so `NetworkGit` overrides just three variables for its call: `GIT_ALLOW_PROTOCOL=file:https`, no `GIT_WORK_TREE`, and `GIT_TERMINAL_PROMPT=0` (git never asks for a password). `HardenedGit` itself is unchanged.

## Retries

`NetworkGit::run(GitCall)` (`src/Core/Git/Contract/`) runs a read-only network git call. If it fails it waits 10 seconds (`ClockInterface::sleep()`) and tries again, then waits 30 seconds and tries a last time. Each wait is logged as a warning with the attempt, the delay and git's message. After the third failure it throws `NetworkGitFailed` ("git failed after 3 attempts: ..." followed by git's own error text); the console command prints it and exits with 1. A call is limited to 600 seconds per attempt. An attempt that hits that limit, is killed by a signal or cannot start counts as a failed attempt and is retried the same way; its reason joins git's message.

Only calls that read from a remote go through `NetworkGit`: a retried write could happen twice.

The tests prove the delays with a clock that records each sleep and never waits (`tests/Core/Git/RecordingClock.php`); one test makes the remote appear during the first sleep to prove that a call that fails once succeeds on the retry.
