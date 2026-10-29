# Requirements

## The hardened git call

A builder writes the repository content Intakt later reads with `git`, so the repository's own config and attributes must never run code or change what is read. Every `git` call goes through `Core/Git/Contract/HardenedGit`: the local git forge (`Plugin/LocalGitForge`) and the gates (`config/gates/src/ProcessRunner::git()`, reached through `Workspace::git()`). No other code starts `git`.

`HardenedGit::command()` builds the argument list (no shell):

```
git --no-replace-objects --literal-pathspecs
    -c core.hooksPath=/dev/null -c core.fsmonitor=false
    -c diff.external= -c core.attributesFile=/dev/null <arguments>
```

- `--no-replace-objects`: a `refs/replace/` ref cannot swap in another commit or blob.
- `--literal-pathspecs`: a path is read as its exact name, so a leading `:` is not pathspec magic.
- `core.hooksPath=/dev/null`: no repository hook runs.
- `core.fsmonitor=false`: no repository fsmonitor command runs.
- `diff.external=` and `core.attributesFile=/dev/null`: no external diff driver and no attributes file outside the repository. Attributes in the repository (`.gitattributes`) still apply, so the forge's diff also passes `--no-ext-diff --no-textconv`, which keeps a `diff.<driver>.textconv` command from running.

`HardenedGit::environment()` gives the environment of the process:

- every inherited `GIT_*` variable is removed (such as `GIT_DIR` inside a git hook);
- `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`, and `HOME` and `XDG_CONFIG_HOME` set to a path that does not exist, so neither the system nor a user config is read;
- the same four settings as `GIT_CONFIG_COUNT`, `GIT_CONFIG_KEY_n` and `GIT_CONFIG_VALUE_n`, for tools that start `git` themselves: the gitleaks gates pass this environment.

Tests: `tests/Core/Git/Contract/HardenedGitTest.php` runs a repository whose config sets a hook and an fsmonitor command and shows neither runs (each test first shows the command runs without hardening); `LocalGitForgeTest::testItRunsNoCommandThatTheRepositoryConfigures` adds a textconv driver and an external diff command; `ProcessRunnerTest::testGitIgnoresAnFsmonitorCommandOfTheRepository` covers the gates.
