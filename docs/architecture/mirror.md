# Bare mirror and retried network git calls

Intakt keeps one bare mirror per project at `var/mirrors/<project>.git` (the container parameter `intakt.git.mirrors_directory`, `Intakt\Core\Git\Contract\GitServices::MIRRORS_DIRECTORY`). The mirror is a cache of the remote: if it is lost, the next update clones it again.

## Updating a mirror

`php bin/console intakt:mirror:update <project> <remote>` (`UpdateMirror` in `src/Core/Git/UseCase/UpdateMirror/`) does one of two things:

- no mirror yet: `git clone --mirror --quiet -- <remote> <path>`;
- a mirror exists: `git fetch --prune --quiet` inside it, so it never clones twice and a branch the remote deleted disappears.

The project name becomes a folder name, so a name must start with a letter or a digit, hold only letters, digits, `.`, `_` and `-`, and not contain `..`. Anything else is refused before git runs. The remote is passed after `--`, so it can never be read as an option. The remote is an argument because a project has no remote setting yet.

Both calls are built by `HardenedGit` and run by `NetworkGit`. `HardenedGit` allows no transport protocol and pins a work tree, so `NetworkGit` overrides just three variables for its call: `GIT_ALLOW_PROTOCOL=file:https`, no `GIT_WORK_TREE`, and `GIT_TERMINAL_PROMPT=0` (git never asks for a password). `HardenedGit` itself is unchanged.

## Retries

`NetworkGit::run(GitCall)` (`src/Core/Git/Contract/`) runs a read-only network git call. If it fails it waits 10 seconds (`ClockInterface::sleep()`) and tries again, then waits 30 seconds and tries a last time. Each wait is logged as a warning with the attempt, the delay and git's message. After the third failure it throws `NetworkGitFailed` ("git failed after 3 attempts: ..." followed by git's own error text); the console command prints it and exits with 1. A call is limited to 600 seconds per attempt.

Only calls that read from a remote go through `NetworkGit`: a retried write could happen twice.

The tests prove the delays with a clock that records each sleep and never waits (`tests/Core/Git/RecordingClock.php`); one test makes the remote appear during the first sleep to prove that a call that fails once succeeds on the retry.
