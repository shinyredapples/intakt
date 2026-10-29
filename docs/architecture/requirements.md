# Requirements

## The hardened git call

A builder writes the repository content Intakt later reads with `git`, so the repository's own config and attributes must never run code or change what is read. Every `git` call is built by one of two equal classes: `Core/Git/Contract/HardenedGit` for the local git forge (`Plugin/LocalGitForge`), and `config/gates/src/HardenedGitCall.php` for the gates (`ProcessRunner::git()`, reached through `Workspace::git()`). The gates keep their own copy because `config/gates/` is a protected path, so no builder can weaken how a gate reads the repository; `tests/Gates/HardenedGitCallTest.php` fails when the two build a different command or environment. No other code starts `git`.

`command()` builds the argument list (no shell):

```
git --no-replace-objects --literal-pathspecs
    --attr-source=4b825dc642cb6eb9a060e54bf8d69288fbee4904
    -c core.hooksPath=/dev/null -c core.fsmonitor=false
    -c diff.external= -c core.attributesFile=/dev/null
    -c log.showSignature=false
    -c gpg.program=/dev/null -c gpg.ssh.program=/dev/null -c gpg.x509.program=/dev/null
    -c i18n.logOutputEncoding=UTF-8 <arguments>
```

- `--no-replace-objects`: a `refs/replace/` ref cannot swap in another commit or blob.
- `--literal-pathspecs`: a path is read as its exact name, so a leading `:` is not pathspec magic. `commandWithPathspecMagic()` leaves it out for the two gate calls that pass their own `:(glob)` pathspecs, never a path from the repository.
- `--attr-source=` git's empty tree: no `.gitattributes` of the repository applies (no filter, diff driver, `binary` or `eol` attribute from the tree). A SHA-256 repository refuses this SHA-1 tree, so every call in it fails instead of reading attributes.
- `core.hooksPath=/dev/null`: no repository hook runs.
- `core.fsmonitor=false`: no repository fsmonitor command runs.
- `diff.external=` and `core.attributesFile=/dev/null`: no external diff command and no attributes file outside the repository.
- `log.showSignature=false` and the three `gpg.*program` settings: `git log` and `git show` verify no signature, so a commit with a `gpgsig` header never starts the gpg, ssh or x509 program the repository config names. A call that asked for `--show-signature` would try to run `/dev/null` and report that failure instead.
- `i18n.logOutputEncoding=UTF-8`: commit messages are printed as UTF-8, so an encoding such as `ASCII//TRANSLIT`, which turns a long dash into `--`, cannot rewrite a subject before the commit gates read it.

`environment(directory)` gives the environment of the process, or throws `RepositoryAttributesRefused`:

- The repository is refused when `$GIT_DIR/info/attributes` exists. Git reads that file on top of every other attributes source, and no option, setting or variable switches it off, so a `-diff` line or a `diff=<driver>` line there could show a changed file as `Binary files differ` or select a driver from the repository config. The file is located with `git rev-parse --git-path info/attributes`, run hardened, so the common directory of a worktree or a submodule is found too; when git cannot name it, the directory is no repository and the call itself fails. The forge turns the refusal into a `ForgeReadFailed`; `bin/check` reports it as the failure of every gate that reads git, with the reason. The check runs when the call is built, right before it: a file written between the two is not caught.
- Every inherited `GIT_*` variable is removed (such as `GIT_DIR` inside a git hook).
- `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`, and `HOME` and `XDG_CONFIG_HOME` set to a path that does not exist, so neither the system nor a user config is read.
- `GIT_NO_REPLACE_OBJECTS=1`, `GIT_ATTR_SOURCE` set to the empty tree and `GIT_ATTR_NOSYSTEM=1`, set after the inherited variables are removed.
- The nine `-c` settings as `GIT_CONFIG_COUNT`, `GIT_CONFIG_KEY_n` and `GIT_CONFIG_VALUE_n`.

With the tree's `.gitattributes` replaced by the empty tree, the attributes file and the system attributes switched off and `info/attributes` refused, no attribute reaches a call, so no `filter.<driver>` or `diff.<driver>` section of the repository config is ever selected: the config is not read for them, and a driver added while a call runs changes nothing.

The forge's diff passes `--text --no-ext-diff --no-textconv`: every file is shown as text, so neither an attribute nor git's own detection replaces a change by `Binary files differ`, and no textconv or external diff driver runs. The gitleaks history gate passes the same three options to `git log`. Tools that start `git` themselves get the same protection from the environment: the gitleaks gates pass it, so gitleaks's `git log -p` reads no replacement object and applies no attribute.

Not covered: settings that only change how git formats what it prints (`diff.noprefix`, `diff.srcPrefix`, `diff.dstPrefix`, `diff.algorithm`, `core.abbrev`) are left as the repository sets them; a patch stays a correct patch, and the gates read paths with `-z` and hashes and subjects with `--format`.

Tests, each first showing that the command runs (or the replacement, attribute or setting applies) without hardening:

- `tests/Core/Git/Contract/HardenedGitTest.php`: a hook, an fsmonitor command, a clean filter named in `.gitattributes` and the gpg program for a commit with a `gpgsig` header do not run; an output encoding does not rewrite a subject; a repository with `info/attributes` is refused.
- `tests/Gates/HardenedGitCallTest.php`: with only the environment, `git log -p` runs no textconv driver named in `.gitattributes` and reads no replacement object, and a repository with `info/attributes` gets no environment.
- `LocalGitForgeTest`: `testItRunsNoCommandThatTheRepositoryConfigures` (textconv, external diff, fsmonitor), `testItRefusesARepositoryWhoseInfoAttributesExistBecauseTheyCouldHideAFileAsBinary` and `testItShowsABinaryFileAsTextSoThatNothingHidesAChangeAsBinaryFilesDiffer`.
- `CheckCommandTest::testAGateThatReadsGitFailsWithTheReasonWhenInfoAttributesExist`, `ScopeTest::testChangedPathsRunNoCleanFilterThatTheRepositoryConfigures` and `ProcessRunnerTest::testGitIgnoresAnFsmonitorCommandOfTheRepository`.
