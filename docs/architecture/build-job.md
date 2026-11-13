# Build job

Plan task T49, the job itself (#1709; design: [overview](../design/05-architecture/01-overview.md), [threat model](../design/05-architecture/14-threat-model.md#builder)). A build job runs the Claude Code CLI on the [builder prompt](builder-prompt.md) in a [job container](job-containers.md) of its own, and returns a git bundle and the pull request text. Not built yet: the turn limit, the bound by the allowance (T60) and a cut session keeping its commits; the structured question that parks a task; the builder's own check tool in the job; reading usage from the session log; the account credential in the job (the job holds none yet, so a real build cannot sign in to the vendor).

`php bin/console intakt:build:run <project> <clone> <branch> <task> <image> <bundle> [--tier=<tier>] [--decision=<text> ...]` (`RunBuildJobCommand`) calls `UseCase/RunBuildJob/RunBuildJob::run(BuildJob)` (marked `#[NoDiscard]`) in `Core/Build`. Nothing calls it from another module yet: the build lane (T56) will.

## The steps

1. **Model:** `TierResolver::resolve(<project>, <tier>)` picks the model from the project's tier map ([Capability tiers](capability-tiers.md)); the tier is the planner's choice, given by the caller (`--tier`, `standard` by default). A tier on any provider but `claude_code` is `BuilderNotOnClaudeCode`, because the job runs the Claude Code CLI and holds no vendor API key; a tier that cannot be resolved (`TierResolutionFailure`) is thrown as it is. No job starts in either case.
2. **Clone:** `<clone>` is a [build clone](build-clone.md) made with `intakt:build:clone` (resolved with `realpath`); a path that is no folder is an `InvalidArgumentException`. The job gets the build clone, never the mirror, so it sees main, the task's branch and a verified source and no other branch.
3. **Prompt:** `BuilderPrompt::textFor()` of the `BuildBrief`, written to a file of its own, `var/prompts/prompt-<random>.md` (mode 0600), which is removed after the job, also when the job throws.
4. **Job** (`JobRunner::run()`):
   - the image given, as the project's job image ([Job images](job-images.md));
   - two read-only inputs, the build clone at `/input/clone` and the prompt file at `/input/prompt.md`;
   - the command `/bin/sh -c <docker/job/build.sh> intakt-build /input/clone /input/prompt.md <branch> <model>`, the script's text read from the project folder for each job;
   - an allowlist of `api.anthropic.com` and the gate's registry hosts (`Gate::registryHosts()`), because the builder installs the project's dependencies;
   - the limits 4 GiB of memory, 2 CPUs and 3600 seconds (builder's choice, until the turn limit and the allowance bound a session), and the job id `build-<first 40 hex digits of the SHA-256 of the length-prefixed project and the branch>`, so a second build of the same branch of the same project while one runs is refused as already running.
5. **Outcome:** read from the job's output directory only (`JobResult::$output`), never from the container's exit code. `BuildJobOutcome` holds a `BuildVerdict` (a backed enum whose `reason()` is fixed text), the bundle's bytes, the pull request text and `build.log`:
   - `MemoryKilled` or `TimedOut` when the engine stopped the job, whatever its output says, with no bundle and no text;
   - `NoResult` when the runner refuses the output (`JobOutputRefused`: a link, too many files, more than 16 MiB in all, the bundle included);
   - `NoCommits` without `handback.bundle`, `NoPullRequestText` without `pull-request.md`, otherwise `Built`.

Both the bundle and the text are untrusted: the [hand-back check](hand-back.md) reads the bundle before anything trusts it, and the checks of the text are plan tasks T50 and T51.

## In the container

`docker/job/build.sh` (POSIX `sh`, with `git` and `claude`) runs as the job's command after the [start check](job-containers.md#start-check):

1. The branch must pass `git check-ref-format --branch` with the name itself as the answer.
2. `git init --initial-branch=<branch> --object-format=<the build clone's>` in `$TMPDIR/clone` (`/work/clone`), then `git fetch --no-tags --no-write-fetch-head --update-head-ok` of the build clone's `refs/heads/*` and `refs/source/*`, and `git checkout --force <branch>`. The clone therefore holds what the build clone holds and no remote. Its git identity is `Intakt builder <builder@intakt.invalid>` (builder's choice), because the job has no git configuration and the orchestrator signs the commits before a push. A step that fails ends the job with no bundle and no text, and the CLI does not run.
3. `claude -p --model <model> --output-format json --dangerously-skip-permissions` in the clone, the prompt file on its standard input, with `CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1`, because the allowlist lets only the vendor's API through. The container is the sandbox, so the CLI asks for no permission. Its output and errors go to `build.log`, followed by `claude exited with <status>`.
4. `PR.md` in the clone's root, when it is a regular file and not a link, is copied to `pull-request.md`.
5. `git bundle create <output>/handback.bundle refs/heads/<branch> ^<the branch's commit before the CLI ran>`: the task's branch by its full name, whatever the builder checked out, with the starting commit as the prerequisite. Git refuses an empty bundle, so a build without a new commit leaves none.

The script exits with the CLI's status. `HOME` and `TMPDIR` are the job's own tmpfs work area.

## The console door

The command reads `<task>` as the task's text and each `--decision` as one decision, in order; it runs first builds only (a rework's act list and CI excerpt have no console form). A `Built` outcome writes the bundle to `<bundle>`, prints `bundle <bundle>`, `pull request text:` and the text (escaped for the console), and exits with 0. Any other verdict prints the last 40 lines of `build.log` and `failed: <reason>` in an error block, writes no bundle and exits with 1. An unknown tier, a clone that is no folder, a task file that cannot be read, a tier that cannot be resolved or is not on `claude_code`, and a `ReportableFailure` (for example `DockerEngineFailed` without a Docker socket) print their message and exit with 1.

## Tests

`tests/Core/Build/BuildScriptTest.php` runs the real `docker/job/build.sh` with `sh -c` on a temporary build clone, with a scripted `claude` on the path that commits and writes `PR.md`, so a scripted CLI returns a bundle and the text; `RunBuildJobTest` and `RunBuildJobCommandTest` run the use case and the command with a stubbed `JobRunner` and `TierResolver`, because the build sandbox has no Docker daemon. No build job has run against a live engine yet.
