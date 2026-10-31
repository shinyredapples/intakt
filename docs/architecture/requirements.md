# Requirements and external commands

Every program outside PHP's own code that Intakt or its checks call, who calls it, and whether you need it. A change that adds a new external command adds it here in the same change.

## To run Intakt

Only **Docker or Podman**. The container image ships everything below that Intakt needs at runtime (planned with the image, plan task T22).

## To work on Intakt

| Command | Called by | What for | Needed | Version | In the image |
|---|---|---|---|---|---|
| `php` | everything; the upstream reproduction runner, `bin/instance` and `bin/probe` run it as `PHP_BINARY` | the application, reproductions, booting an instance, probes | yes | 8.5.12 or later (CI refuses older: PHP2Shell) | yes |
| `composer` | `composer install`; the `composer audit` gate | installing dependencies, the security advisory check | yes | 2.x | development only |
| `git` | the local git forge (`src/Plugin/LocalGitForge/`); the commit-subject, history-secrets, long-dashes and scope gates | reading changes and history | yes | 2.45 or later (`--no-lazy-fetch`) | yes |
| `sh` | `bin/instance`, `bin/probe`, the gate runner | closing an inherited file descriptor before handing over; bringing up loopback | yes (POSIX) | any | yes |
| `gitleaks` | the secrets gates (files and history) | secret scanning | for `bin/check` | exactly 8.30.1, or point `INTAKT_GITLEAKS` at that version | development only |
| `unshare` (util-linux) | the gate runner (contained test runs), `bin/probe` | running tests and probes in their own namespaces | for `bin/check` and probes | with user namespaces enabled | development only |
| `env` (coreutils) | the gitleaks gate | running gitleaks in the scanned directory (`env --chdir`) | for `bin/check` | coreutils 8.28 or later | development only |
| `getconf` (POSIX) | the tools preflight of `bin/check`, only when `PATH` is unset | reading the default path that commands started from an argument list are searched on | for `bin/check` without `PATH` | any | development only |
| `true` | the gate runner | probing once whether `unshare` may create namespaces | for `bin/check` | any | development only |
| `setsid` (util-linux) | the gate runner | putting a contained run in its own session, so it can be stopped as a whole | for `bin/check` | any | development only |
| `bash` | the tests gate (`tests/Gates/ShowcaseFiguresTest.php`, `ReleaseLabelsTest.php`), `docs/showcase/figures.sh` | running the showcase script and the release-labels workflow script offline against fixtures | for `bin/check` | any | development only |
| `jq` | the tests gate (`tests/Gates/ShowcaseFiguresTest.php`, its fake `gh`), `docs/showcase/figures.sh` | computing the showcase figures from the API's JSON | for `bin/check` | 1.7 or later | development only |
| `gh` | `docs/showcase/figures.sh` (the test puts a fake one first on `PATH`); `.github/workflows/release-labels.yml` on GitHub's runner | reading pull requests and comments from the GitHub API; labeling a pull request | only for the showcase figures | any | no |
| `ip` (iproute2) | `bin/probe` | bringing loopback up inside a fresh network namespace | for probes | any | development only |
| `vendor/bin/phpunit` and the other Composer tools | the gates, `bin/probe` | tests, static analysis, code style | yes | as locked in `composer.lock` | development only |

`unshare`, `setsid` and `ip` are Linux tools, so the full `bin/check` runs on Linux (or in the development container, plan task T35). On another system, run it in that container.

## To run Intakt's own CI

`.github/workflows/ci.yml` and the scripts in `.github/ci/` call these on the runner host, outside the CI image. GitHub's `ubuntu-24.04` runners have them all; a self-hosted runner must provide them and must be ephemeral ([Running Intakt's CI on your own runner](../user/self-hosted-ci.md)).

| Command | Called by | What for | Needed |
|---|---|---|---|
| `docker` (CLI and a running daemon the runner's user may use) | `build-image.sh`, the `check` and `persistence` jobs, the runner for the `persistence` service container | building the CI image, running `bin/check` and the persistence group in it, the database service | yes |
| `docker run` security options | the `check` job | `--security-opt seccomp=.github/ci/seccomp-userns.json` and `systempaths=unconfined` everywhere, `apparmor=unconfined` on GitHub-hosted runners only, so the gate tests can create a user namespace and mount `/proc`; a self-hosted host needs unprivileged user namespaces and no AppArmor profile blocking mount | yes |
| `sudo sysctl` | the `check` job, GitHub-hosted runners only | `kernel.apparmor_restrict_unprivileged_userns=0` | on GitHub's runners |
| `sh`, `sha256sum`, `cut` | `build-image.sh` | the image tag from the Dockerfile's hash | yes |
| `bash`, `git`, `grep` | the `changes` job | reading which paths a pull request touches | yes |

Inside the image, `.github/ci/run-on-copy.sh` uses `php`, `cp` and `composer` (above).

## Called when a tier maps to `claude_code`

| Command | Called by | What for | Needed | Version | In the image |
|---|---|---|---|---|---|
| `claude` (Claude Code) | `symfony/ai-claude-code-platform` through `ConfinedClaudeCodeClient` | a reviewer on a subscription login, called as `claude --output-format stream-json --verbose --include-partial-messages --tools "" --setting-sources "" --strict-mcp-config --no-session-persistence --append-system-prompt <schema> --model <name> -p <prompt>` | only for that provider | a release with those flags (2.1.302 accepts them) | no |

## Coming with planned parts

| Command | Where | What for |
|---|---|---|
| `git` | every job image | builders and reviewers work in a git checkout |
| the AI companies' command-line tools (Claude Code, Codex) | every job image | running builders and reviewers on a subscription |
| the Docker or Podman API | the orchestrator | starting job containers and sidecars, through the socket (an API, not a command) |

## When a tool is missing

Before it runs any gate, `bin/check` looks up the commands of the selected gates by name on `PATH`, as `config/gates/src/RequiredTools.php` lists them:

| Gates | Commands looked up |
|---|---|
| every gate | `git` |
| `audit` | `composer` |
| `tests` | `sh`, `unshare`, `setsid`, `true`, `bash`, `jq` |
| `secrets`, `secrets-history` | `gitleaks`, `env` |

Only the gates that `--only` and `--skip` leave selected count: a command only a skipped gate needs is not looked up, and when they leave no gate selected nothing is looked up, `git` included. When `INTAKT_GITLEAKS` is set, its value replaces `gitleaks` in the lookup and in the message; a value with a `/` is checked as a path to an executable file, a relative one from the workspace root (`--root`, else the repository) where the secrets gates run it. A value that names a command another selected gate needs (such as `env`) is still listed once. A name without a `/` is looked up as starting it in the workspace root does: the `PATH` entries are walked in order, an absolute entry as it is and a relative or empty entry (such as `tools` or `.`) from the workspace root, an empty entry being the root itself; the first entry that holds an executable file of that name makes the command present, and none makes it missing. A command only under a relative entry of the directory `bin/check` was started in therefore counts as missing, unless a later entry holds it. When `PATH` is unset, the entries are the C library's default path, which it searches when it starts a command from an argument list as the gates do (Symfony Process), as `getconf PATH` prints it (started from an argument list with `PATH` removed; `/bin:/usr/bin` with glibc); if `getconf` cannot be started or fails, only the workspace root is searched.

When any command is missing, no gate runs: `bin/check` prints one JSON line naming every missing command once, `{"error":"missing tools","missing":["<command> not found, see docs/architecture/requirements.md", ...]}`, and exits with 2. A `gitleaks` that is found but is not version 8.30.1 still fails the secrets gates with their own message (`gitleaks 8.30.1 is required, set INTAKT_GITLEAKS to it or put it first on the path, an override is checked too, see docs/architecture/requirements.md`).

Tests: `tests/Gates/RequiredToolsTest.php` (every missing command listed once, a gate not selected requires nothing, the `tests` gate needs `bash` and `jq`, the override is looked up instead of `gitleaks`, a relative override is found in the workspace root, an override naming another required command is listed once, a command under a relative `PATH` entry of the workspace root is found and one only under such an entry of the caller's directory is listed, one under such an entry of the caller's directory and a later absolute entry is found, with `PATH` unset a command on the default path is found and one off it is listed, and with `PATH` unset and no way to start a process only the workspace root is searched, the message) and `CheckCommandTest::testMissingToolsAreListedBeforeAnyGateRuns` and `testAMissingToolOfAGateThatIsSkippedDoesNotStopTheRun`.

## How Intakt calls them

- **Through Symfony Process with an argument list, never a shell string:** the local git forge, the gates (through their runner) and the upstream reproduction runner. `bin/instance` and `bin/probe` start their processes with PHP's `proc_open` and an argument list; where they use `sh`, the command after it is passed as separate arguments.
- **With a time limit** on every call made through Process.
- **Every `git` call is hardened** the same way, in the local git forge and in the gates: the repository's own config and attributes can run no code and change nothing that is read ([below](#the-hardened-git-call)).

## The hardened git call

A builder writes the repository content Intakt later reads with `git`, so the repository's own config and attributes must never run code or change what is read. Every `git` call is built by one of two equal classes: `Core/Git/Contract/HardenedGit` for the local git forge (`Plugin/LocalGitForge`), and `config/gates/src/HardenedGitCall.php` for the gates (`ProcessRunner::git()`, reached through `Workspace::git()`). The gates keep their own copy because `config/gates/` is a protected path, so no builder can weaken how a gate reads the repository; `tests/Gates/HardenedGitCallTest.php` fails when the two build a different command or environment. No other code starts `git`.

`call(arguments, directory)` returns a `GitCall`, a typed object with the argument list (no shell), the absolute directory the process runs in, and the environment; `callWithPathspecMagic()` does the same without `--literal-pathspecs`. `environment(directory)` alone serves a tool that starts `git` itself (gitleaks). The argument list:

```
git --no-replace-objects --no-lazy-fetch --literal-pathspecs
    --attr-source=<empty tree>
    -c core.hooksPath=/dev/null -c core.fsmonitor=false
    -c diff.external= -c core.attributesFile=/dev/null
    -c log.showSignature=false
    -c gpg.program=/dev/null -c gpg.ssh.program=/dev/null -c gpg.x509.program=/dev/null
    -c i18n.logOutputEncoding=UTF-8 -c core.ignoreCase=false <arguments>
```

- `--no-replace-objects`: a `refs/replace/` ref cannot swap in another commit or blob.
- `--no-lazy-fetch` (git 2.45 or later; an older git refuses the option, so every call fails): a partial clone (`extensions.partialClone`) fetches no missing object, so the transport of a promisor remote the repository config names (an `ext::<command>` URL with `protocol.ext.allow=always`, say) never starts; the call fails on the missing object instead (`Not a valid object name`, which the forge turns into `ForgeReadFailed`).
- `--literal-pathspecs`: a path is read as its exact name, so a leading `:` is not pathspec magic. `callWithPathspecMagic()` leaves it out for the two gate calls that pass their own `:(glob)` pathspecs, never a path from the repository.
- `--attr-source=` git's empty tree in the repository's object format (`4b825dc642cb6eb9a060e54bf8d69288fbee4904` for SHA-1, `6ef19b41225c5369f1c104d45d8d85efa9b057b53b14b4b9b939dd74decc5321` for SHA-256): no `.gitattributes` of the repository applies (no filter, diff driver, `binary` or `eol` attribute from the tree). The format comes from `git rev-parse --show-object-format`, asked together with the `info/attributes` check below; when git cannot answer, or names a format other than these two, the SHA-1 tree is used, which git refuses in another format, so a command that consults attributes fails with `bad --attr-source` instead of reading them.
- `core.hooksPath=/dev/null`: no repository hook runs.
- `core.fsmonitor=false`: no repository fsmonitor command runs.
- `diff.external=` and `core.attributesFile=/dev/null`: no external diff command and no attributes file outside the repository.
- `log.showSignature=false` and the three `gpg.*program` settings: `git log` and `git show` verify no signature, so a commit with a `gpgsig` header never starts the gpg, ssh or x509 program the repository config names. A call that asked for `--show-signature` would try to run `/dev/null` and report that failure instead.
- `i18n.logOutputEncoding=UTF-8`: commit messages are printed as UTF-8, so an encoding such as `ASCII//TRANSLIT`, which turns a long dash into `--`, cannot rewrite a subject before the commit gates read it.
- `core.ignoreCase=false`: paths compare case-sensitively, so a repository's `core.ignoreCase=true` cannot hide an untracked `SAFE.php` beside a tracked `safe.php` from the untracked listing.

`environment(directory)` gives the environment of the process, or throws `RepositoryAttributesRefused`. The directory is made absolute first (`realpath`; a directory that does not exist stays as given, and the call itself fails there), because the process changes into it before git reads `GIT_WORK_TREE`, so a relative one, such as a relative `--root` of `bin/check`, would be resolved twice and `git status` would report that it must run in a work tree:

- The repository is refused when `$GIT_DIR/info/attributes` exists. Git reads that file on top of every other attributes source, and no option, setting or variable switches it off, so a `-diff` line or a `diff=<driver>` line there could show a changed file as `Binary files differ` or select a driver from the repository config. The file is located with `git rev-parse --show-object-format --git-path info/attributes`, run hardened, so the common directory of a worktree or a submodule is found too; when git cannot name it, the directory is no repository and the call itself fails. The forge turns the refusal into a `ForgeReadFailed`; `bin/check` reports it as the failure of every gate that reads git, with the reason. The check runs when the call is built, right before it, so a file written between the two is not refused; what such a file could still do is closed by the calls themselves (below).
- Every inherited `GIT_*` variable is removed (such as `GIT_DIR` inside a git hook).
- `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`, and `HOME` and `XDG_CONFIG_HOME` set to a path that does not exist, so neither the system nor a user config is read.
- `GIT_NO_REPLACE_OBJECTS=1`, `GIT_ATTR_SOURCE` set to the empty tree and `GIT_ATTR_NOSYSTEM=1`, set after the inherited variables are removed.
- `GIT_NO_LAZY_FETCH=1`: the same as `--no-lazy-fetch`, for a tool that starts `git` itself.
- `GIT_ALLOW_PROTOCOL` set to the empty string: the variable is an allowlist that beats every `protocol.<name>.allow` of the repository, and an empty one allows no transport (`transport 'ext' not allowed`), so no remote helper, `core.sshCommand` or credential helper the repository names can run from any fetch. A `-c protocol.allow=never` would not do, because the repository's more specific `protocol.ext.allow` wins over it.
- `GIT_GRAFT_FILE` set to a path that does not exist: a `.git/info/grafts` file cannot give a commit other parents, which would hide the commits in between from `git log` and `git rev-list` as a replace object would.
- `GIT_WORK_TREE` set to the directory the call runs in: a `core.worktree` in the repository config cannot point working-tree reads (changed and untracked files) at another directory.
- The ten `-c` settings as `GIT_CONFIG_COUNT`, `GIT_CONFIG_KEY_n` and `GIT_CONFIG_VALUE_n`.

With the tree's `.gitattributes` replaced by the empty tree, the attributes file and the system attributes switched off and `info/attributes` refused, no attribute reaches a call. An `info/attributes` written after the check could still name a driver, so no call depends on the refusal alone:

- Every call that shows a diff passes `--text --no-ext-diff --no-textconv`: the forge's diff and the gitleaks history gate's `git log` (also handed to gitleaks), and the `git log -G` searches of that gate. Every file is shown as text, so neither an attribute nor git's own detection replaces a change by `Binary files differ`, and no textconv or external diff driver runs.
- No call reads the working tree through a filter. The gates' changed paths (`Scope::changedPaths()`) compare the index with the base by `git diff-index --cached`, then hash the tracked files with `git hash-object --no-filters` and compare those hashes and the owner's execute bit with the blobs and modes of `git ls-files --stage`, so a mode-only change counts as changed; an entry that is conflicted, a link or a submodule, or whose file is missing or not a regular file, counts as changed. So no clean filter runs, whatever attributes git sees. The forge reads commits and trees only.
- The gates list untracked files (`Scope::changedPaths()`, and the repository files of `Scope::allPhpFiles()`) with `git ls-files --others --exclude-per-directory=.gitignore`: only the `.gitignore` files of the tree exclude a file, never `info/exclude` or a `core.excludesFile` of the repository config, which no diff shows.

Tools that start `git` themselves get the same protection from the environment: the gitleaks gates pass it, so gitleaks's `git log -p` reads no replacement object or graft and applies no attribute.

Not covered: settings that only change how git formats what it prints (`diff.noprefix`, `diff.srcPrefix`, `diff.dstPrefix`, `diff.algorithm`, `core.abbrev`) are left as the repository sets them; a patch stays a correct patch, and the gates read paths with `-z` and hashes and subjects with `--format`.

Tests, each first showing that the command runs (or the replacement, attribute or setting applies) without hardening:

- `tests/Core/Git/Contract/HardenedGitTest.php`: a hook, an fsmonitor command, a clean filter named in `.gitattributes` and the gpg program for a commit with a `gpgsig` header do not run; an output encoding does not rewrite a subject; a graft file does not hide a commit; a `core.worktree` does not redirect working-tree reads; a promisor remote with an `ext::` command does not run it when `cat-file` reads a removed blob, and the call fails; a relative directory is pinned as an absolute work tree; `core.ignoreCase=true` does not hide a file beside one that differs only in case; a repository with `info/attributes` is refused; a SHA-256 repository gets its own empty tree and `diff` over a changed file succeeds.
- `tests/Gates/HardenedGitCallTest.php`: with only the environment, `git log -p` runs no textconv driver named in `.gitattributes`, reads no replacement object, follows no graft file and runs no `ext::` command of a promisor remote for a removed blob; `ProcessRunner::git()` with a relative directory reads the working tree; a repository with `info/attributes` gets no environment; a SHA-256 repository gets its own empty tree.
- `LocalGitForgeTest`: `testItRunsNoCommandThatTheRepositoryConfigures` (textconv, external diff, fsmonitor), `testItReturnsTheDiffOfABranchInASha256Repository`, `testItRefusesARepositoryWhoseInfoAttributesExistBecauseTheyCouldHideAFileAsBinary` and `testItShowsABinaryFileAsTextSoThatNothingHidesAChangeAsBinaryFilesDiffer`.
- `ScopeTest`: `testChangedPathsRunNoCleanFilterThatTheRepositoryConfigures`, `testChangedPathsRunNoCleanFilterThatInfoAttributesNameAfterTheirCheck` (a `git` on the path writes `info/attributes` after each check and removes it after each call, the order a racing writer needs), `testChangedPathsReadTheWorkingTreeEvenWhenTheRepositoryConfigPointsElsewhere`, `testChangedPathsIncludeAFileBesideOneThatDiffersOnlyInCaseWhenTheRepositoryIgnoresCase`, `testChangedPathsIncludeAFileThatTheRepositoryConfigExcludes` and `testChangedPathsIncludeAFileThatTheRepositoryInfoExcludeNames`.
- `CheckCommandTest::testAGateThatReadsGitFailsWithTheReasonWhenInfoAttributesExist`, `CommitSubjectsGateTest::testTheBaseBranchCheckOfATestMergeIgnoresAnInheritedGitDirectory` and `ProcessRunnerTest::testGitIgnoresAnFsmonitorCommandOfTheRepository`.
