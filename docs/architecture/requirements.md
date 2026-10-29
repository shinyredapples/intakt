# Requirements

## The hardened git call

A builder writes the repository content Intakt later reads with `git`, so the repository's own config and attributes must never run code or change what is read. Every `git` call is built by one of two equal classes: `Core/Git/Contract/HardenedGit` for the local git forge (`Plugin/LocalGitForge`), and `config/gates/src/HardenedGitCall.php` for the gates (`ProcessRunner::git()`, reached through `Workspace::git()`). The gates keep their own copy because `config/gates/` is a protected path, so no builder can weaken how a gate reads the repository; `tests/Gates/HardenedGitCallTest.php` fails when the two build a different command or environment. No other code starts `git`.

`command()` builds the argument list (no shell):

```
git --no-replace-objects --literal-pathspecs
    --attr-source=4b825dc642cb6eb9a060e54bf8d69288fbee4904
    -c core.hooksPath=/dev/null -c core.fsmonitor=false
    -c diff.external= -c core.attributesFile=/dev/null <arguments>
```

- `--no-replace-objects`: a `refs/replace/` ref cannot swap in another commit or blob.
- `--literal-pathspecs`: a path is read as its exact name, so a leading `:` is not pathspec magic. `commandWithPathspecMagic()` leaves it out for the two gate calls that pass their own `:(glob)` pathspecs, never a path from the repository.
- `--attr-source=` git's empty tree: no `.gitattributes` of the repository applies (no filter, diff driver, `binary` or `eol` attribute from the tree). A SHA-256 repository refuses this SHA-1 tree, so every call in it fails instead of reading attributes.
- `core.hooksPath=/dev/null`: no repository hook runs.
- `core.fsmonitor=false`: no repository fsmonitor command runs.
- `diff.external=` and `core.attributesFile=/dev/null`: no external diff command and no attributes file outside the repository. The forge's diff also passes `--no-ext-diff --no-textconv`.

`environment(directory)` gives the environment of the process:

- every inherited `GIT_*` variable is removed (such as `GIT_DIR` inside a git hook);
- `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`, and `HOME` and `XDG_CONFIG_HOME` set to a path that does not exist, so neither the system nor a user config is read;
- `GIT_NO_REPLACE_OBJECTS=1` and `GIT_ATTR_SOURCE` set to the empty tree, set after the inherited variables are removed;
- the four `-c` settings as `GIT_CONFIG_COUNT`, `GIT_CONFIG_KEY_n` and `GIT_CONFIG_VALUE_n`, followed by an override for every filter and diff driver the repository config defines (`git config --get-regexp '^(filter|diff)\.'`, read with the same hardening): `clean`, `smudge`, `process` and `command` become empty, `required` becomes `false`, and `textconv` becomes `cat`, which shows the file as it is. This covers drivers that `.git/info/attributes` names, which `--attr-source` does not replace.

Tools that start `git` themselves get the same protection from the environment: the gitleaks gates pass it, so gitleaks's `git log -p` reads no replacement object, applies no attributes of the tree and runs no filter or textconv driver.

Tests, each first showing that the command runs (or the replacement applies) without hardening:

- `tests/Core/Git/Contract/HardenedGitTest.php`: a hook, an fsmonitor command, a clean filter named in `.gitattributes`, and a textconv driver named in `.git/info/attributes` do not run.
- `tests/Gates/HardenedGitCallTest.php`: with only the environment, `git log -p` runs no textconv driver named in `.gitattributes` and reads no replacement object.
- `LocalGitForgeTest::testItRunsNoCommandThatTheRepositoryConfigures` (textconv, external diff, fsmonitor), `ScopeTest::testChangedPathsRunNoCleanFilterThatTheRepositoryConfigures` and `ProcessRunnerTest::testGitIgnoresAnFsmonitorCommandOfTheRepository`.
