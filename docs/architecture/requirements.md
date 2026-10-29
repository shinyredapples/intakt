# Requirements

## The hardened git call

A builder writes the repository content Intakt later reads with `git`, so the repository's own config and attributes must never run code or change what is read. Every `git` call is built by one of two equal classes: `Core/Git/Contract/HardenedGit` for the local git forge (`Plugin/LocalGitForge`), and `config/gates/src/HardenedGitCall.php` for the gates (`ProcessRunner::git()`, reached through `Workspace::git()`). The gates keep their own copy because `config/gates/` is a protected path, so no builder can weaken how a gate reads the repository; `tests/Gates/HardenedGitCallTest.php` fails when the two build a different command or environment. No other code starts `git`.

`command()` builds the argument list (no shell):

```
git --no-replace-objects --no-lazy-fetch --literal-pathspecs
    --attr-source=4b825dc642cb6eb9a060e54bf8d69288fbee4904
    -c core.hooksPath=/dev/null -c core.fsmonitor=false
    -c diff.external= -c core.attributesFile=/dev/null
    -c log.showSignature=false
    -c gpg.program=/dev/null -c gpg.ssh.program=/dev/null -c gpg.x509.program=/dev/null
    -c i18n.logOutputEncoding=UTF-8 <arguments>
```

- `--no-replace-objects`: a `refs/replace/` ref cannot swap in another commit or blob.
- `--no-lazy-fetch` (git 2.45 or later; an older git refuses the option, so every call fails): a partial clone (`extensions.partialClone`) fetches no missing object, so the transport of a promisor remote the repository config names (an `ext::<command>` URL with `protocol.ext.allow=always`, say) never starts; the call fails on the missing object instead (`Not a valid object name`, which the forge turns into `ForgeReadFailed`).
- `--literal-pathspecs`: a path is read as its exact name, so a leading `:` is not pathspec magic. `commandWithPathspecMagic()` leaves it out for the two gate calls that pass their own `:(glob)` pathspecs, never a path from the repository.
- `--attr-source=` git's empty tree: no `.gitattributes` of the repository applies (no filter, diff driver, `binary` or `eol` attribute from the tree). A SHA-256 repository refuses this SHA-1 tree, so every call in it fails instead of reading attributes.
- `core.hooksPath=/dev/null`: no repository hook runs.
- `core.fsmonitor=false`: no repository fsmonitor command runs.
- `diff.external=` and `core.attributesFile=/dev/null`: no external diff command and no attributes file outside the repository.
- `log.showSignature=false` and the three `gpg.*program` settings: `git log` and `git show` verify no signature, so a commit with a `gpgsig` header never starts the gpg, ssh or x509 program the repository config names. A call that asked for `--show-signature` would try to run `/dev/null` and report that failure instead.
- `i18n.logOutputEncoding=UTF-8`: commit messages are printed as UTF-8, so an encoding such as `ASCII//TRANSLIT`, which turns a long dash into `--`, cannot rewrite a subject before the commit gates read it.

`environment(directory)` gives the environment of the process, or throws `RepositoryAttributesRefused`. The directory is made absolute first (`realpath`; a directory that does not exist stays as given, and the call itself fails there), because the process changes into it before git reads `GIT_WORK_TREE`, so a relative one, such as a relative `--root` of `bin/check`, would be resolved twice and `git status` would report that it must run in a work tree:

- The repository is refused when `$GIT_DIR/info/attributes` exists. Git reads that file on top of every other attributes source, and no option, setting or variable switches it off, so a `-diff` line or a `diff=<driver>` line there could show a changed file as `Binary files differ` or select a driver from the repository config. The file is located with `git rev-parse --git-path info/attributes`, run hardened, so the common directory of a worktree or a submodule is found too; when git cannot name it, the directory is no repository and the call itself fails. The forge turns the refusal into a `ForgeReadFailed`; `bin/check` reports it as the failure of every gate that reads git, with the reason. The check runs when the call is built, right before it, so a file written between the two is not refused; what such a file could still do is closed by the calls themselves (below).
- Every inherited `GIT_*` variable is removed (such as `GIT_DIR` inside a git hook).
- `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`, and `HOME` and `XDG_CONFIG_HOME` set to a path that does not exist, so neither the system nor a user config is read.
- `GIT_NO_REPLACE_OBJECTS=1`, `GIT_ATTR_SOURCE` set to the empty tree and `GIT_ATTR_NOSYSTEM=1`, set after the inherited variables are removed.
- `GIT_NO_LAZY_FETCH=1`: the same as `--no-lazy-fetch`, for a tool that starts `git` itself.
- `GIT_ALLOW_PROTOCOL` set to the empty string: the variable is an allowlist that beats every `protocol.<name>.allow` of the repository, and an empty one allows no transport (`transport 'ext' not allowed`), so no remote helper, `core.sshCommand` or credential helper the repository names can run from any fetch. A `-c protocol.allow=never` would not do, because the repository's more specific `protocol.ext.allow` wins over it.
- `GIT_GRAFT_FILE` set to a path that does not exist: a `.git/info/grafts` file cannot give a commit other parents, which would hide the commits in between from `git log` and `git rev-list` as a replace object would.
- `GIT_WORK_TREE` set to the directory the call runs in: a `core.worktree` in the repository config cannot point working-tree reads (changed and untracked files) at another directory.
- The nine `-c` settings as `GIT_CONFIG_COUNT`, `GIT_CONFIG_KEY_n` and `GIT_CONFIG_VALUE_n`.

With the tree's `.gitattributes` replaced by the empty tree, the attributes file and the system attributes switched off and `info/attributes` refused, no attribute reaches a call. An `info/attributes` written after the check could still name a driver, so no call depends on the refusal alone:

- Every call that shows a diff passes `--text --no-ext-diff --no-textconv`: the forge's diff and the gitleaks history gate's `git log` (also handed to gitleaks), and the `git log -G` searches of that gate. Every file is shown as text, so neither an attribute nor git's own detection replaces a change by `Binary files differ`, and no textconv or external diff driver runs.
- No call reads the working tree through a filter. The gates' changed paths (`Scope::changedPaths()`) compare the index with the base by `git diff-index --cached`, then hash the tracked files with `git hash-object --no-filters` and compare those hashes with `git ls-files --stage`; an entry that is conflicted, a link or a submodule, or whose file is missing or not a regular file, counts as changed. So no clean filter runs, whatever attributes git sees. The forge reads commits and trees only.

Tools that start `git` themselves get the same protection from the environment: the gitleaks gates pass it, so gitleaks's `git log -p` reads no replacement object or graft and applies no attribute.

Not covered: settings that only change how git formats what it prints (`diff.noprefix`, `diff.srcPrefix`, `diff.dstPrefix`, `diff.algorithm`, `core.abbrev`) are left as the repository sets them; a patch stays a correct patch, and the gates read paths with `-z` and hashes and subjects with `--format`.

Tests, each first showing that the command runs (or the replacement, attribute or setting applies) without hardening:

- `tests/Core/Git/Contract/HardenedGitTest.php`: a hook, an fsmonitor command, a clean filter named in `.gitattributes` and the gpg program for a commit with a `gpgsig` header do not run; an output encoding does not rewrite a subject; a graft file does not hide a commit; a `core.worktree` does not redirect working-tree reads; a promisor remote with an `ext::` command does not run it when `cat-file` reads a removed blob, and the call fails; a relative directory is pinned as an absolute work tree; a repository with `info/attributes` is refused.
- `tests/Gates/HardenedGitCallTest.php`: with only the environment, `git log -p` runs no textconv driver named in `.gitattributes`, reads no replacement object, follows no graft file and runs no `ext::` command of a promisor remote for a removed blob; `ProcessRunner::git()` with a relative directory reads the working tree; a repository with `info/attributes` gets no environment.
- `LocalGitForgeTest`: `testItRunsNoCommandThatTheRepositoryConfigures` (textconv, external diff, fsmonitor), `testItRefusesARepositoryWhoseInfoAttributesExistBecauseTheyCouldHideAFileAsBinary` and `testItShowsABinaryFileAsTextSoThatNothingHidesAChangeAsBinaryFilesDiffer`.
- `ScopeTest`: `testChangedPathsRunNoCleanFilterThatTheRepositoryConfigures`, `testChangedPathsRunNoCleanFilterThatInfoAttributesNameAfterTheirCheck` (a `git` on the path writes `info/attributes` after each check and removes it after each call, the order a racing writer needs) and `testChangedPathsReadTheWorkingTreeEvenWhenTheRepositoryConfigPointsElsewhere`.
- `CheckCommandTest::testAGateThatReadsGitFailsWithTheReasonWhenInfoAttributesExist`, `CommitSubjectsGateTest::testTheBaseBranchCheckOfATestMergeIgnoresAnInheritedGitDirectory` and `ProcessRunnerTest::testGitIgnoresAnFsmonitorCommandOfTheRepository`.
