# Contributing to Intakt

Thank you for looking. Intakt is built mostly by its own loop: AI agents build tasks from the issues, other AI agents review them, and the owner approves what only a person may. People are welcome to contribute too: report a bug, suggest a feature, improve the docs, or send a pull request.

## Before you start

- **Read the design** in [`docs/design/`](docs/design/README.md): it is the authority on what Intakt does and why. If your change disagrees with it, propose the design change first (an issue or a pull request against the design).
- **Read [`AGENTS.md`](AGENTS.md)**: despite its name, it is the code standard for everyone, people and agents alike.
- **Plain-words overviews** are in [`docs/guide/`](docs/guide/README.md).

## Reporting a bug or suggesting a feature

Open an issue. The guide [writing a good task](docs/guide/writing-a-good-task.md) shows what makes an issue easy to act on: what should be different, how to tell it is done, and the evidence that shows it. A bug report with the smallest steps that reproduce it is the most useful thing you can send.

A security problem is the exception: never open a public issue for it. Report it privately as [`SECURITY.md`](SECURITY.md) describes.

## How your issue or pull request is handled

People with write access to the repository decide what gets built. Opening an issue or a pull request asks them; it does not start any work by itself.

- **An issue you open is not built as it stands.** To act on it, a maintainer opens a task of their own from it, with your text copied inside an `<!-- external -->` block. Starting builds from labels is planned: once it is in place, an issue is built only when its author and the last person who edited its text both have write access and such an account labeled it `ready` (the label this repository uses; Intakt's own default is `intakt`). Having Intakt take over your issue directly (the `take-over` label) is planned too.
- **Labels count only when someone with write access added them.** A label you add, or one added by an issue template or a workflow, has no effect on what Intakt does. Bot accounts never steer, even with write access, except Intakt's own and its maintainer agent's, and those two only while they have write access themselves.
- **Intakt's review panel reviews a pull request from outside once CI on its head is green**, in a repository it watches; no label is needed. It only reads a pull request whose author has no write access: it never boots its code. When the repository has Intakt post its reviews, the findings come back as one comment on the pull request; otherwise they go to the maintainers. For now a maintainer merges it by hand. Having Intakt rework it (the `take-over` label) is planned.
- **CI checks nothing on a pull request from a fork:** every CI job runs only for branches of this repository and for main, and skips on a fork's pull request even after a maintainer approves the workflow run. Skipped jobs count as green, so the pull request is still reviewed once that approved run finishes. Run `bin/check` yourself before you open it (see below).

The full rules are in [steering Intakt](docs/guide/steering-intakt.md).

## Working on the code

```mermaid
flowchart LR
  clone[Clone and install] --> branch[A branch for one change]
  branch --> code[Change + tests + docs]
  code --> check[bin/check passes]
  check --> pr[Pull request]
  pr --> review[Reviewed, then merged]
```

1. **Set up:** PHP at the version of `config.platform.php` in `composer.json` with the extensions its `require` lists, and `config/gates/php/check.ini` for its ini settings, Composer, git, and for the full `bin/check` gitleaks (at the version `bin/check` pins) and the Linux tools `unshare`, `setsid` and `ip`: the full list with versions is in [requirements](docs/architecture/requirements.md). On a system other than Linux, run the full `bin/check` on a Linux machine or virtual machine; the [development setup](docs/architecture/development-setup.md)'s container serves the app with file watching but has no Composer. Then `composer install`; `php bin/instance 3>&1 >/dev/null` checks that Intakt boots. `bin/check` checks this profile through its first gate, `environment`, and fails it, naming each difference, when the running PHP differs; it does not load the ini settings itself, so add `config/gates/php` after the directories PHP already scans: `PHP_INI_SCAN_DIR=":$(pwd)/config/gates/php" bin/check` (see [the user guide](docs/reference/user-manual.md#the-php-profile-and-the-environment-gate)).
2. **One change per branch and pull request**, small enough to review in one go.
3. **Tests with every change:** a test that would fail without it, named as a sentence (`testAnEmptyFindingsListIsRefused`), with the coverage attributes `AGENTS.md` asks for.
4. **Docs in the same change:** a user-visible change updates the reference in `docs/reference/`, any chapter of the story guide in `docs/user/` it makes untrue, and `docs/architecture/`; a change to something a guide describes updates the guide in `docs/guide/`; a change that starts using a Symfony or Symfony AI feature updates its row of `docs/highlights.md` through a new fragment in [`docs/highlights.d/`](docs/highlights.d/), never by editing or committing the page itself (`bin/highlights` builds it, and git ignores it).
5. **Run `bin/check`** before every commit. It runs every gate CI runs: tests, static analysis, code style and Intakt's own rules. Run again with the same options plus `--reuse` on a tree that passed and has not changed since, it reuses that pass instead of running the gates again, except the dependency audit, unless the tree is too large to be noted ([details](docs/reference/user-manual.md#running-bincheck-again-on-an-unchanged-tree)); CI never reuses.
6. **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) (`feat(review): ...`, `fix(probe): ...`), at most 100 characters, in US English.

## Protected files

Some files are the project's rules: the gates in `config/gates/`, `bin/check`, `bin/instance`, `bin/probe`, `.github/`, `AGENTS.md` and the PHPUnit configs. A change to them gets extra review, and anything that weakens a check needs the owner's approval ([why](docs/guide/why-the-ai-cant-wreck-your-project.md)). Never weaken a gate to make a change pass; fix the change, or argue the gate in its own pull request.

## Dependencies

A dependency change is decided by the build loop's composer policy, as `AGENTS.md` says:
- **Routine:** a new `symfony/` or `doctrine/` package from that vendor's own repository, resolved to an upstream release tag, with no binaries, no Composer plugin and no autoloaded files or class maps.
- **Waits for the owner:** a package from any other vendor, and any change to Composer's own settings (scripts, plugins, repositories, config). A justification in the pull request (a recent release, real use on Packagist, no open security advisory) helps the owner decide; it does not replace the approval.

## A bug in Symfony AI or another dependency

Intakt uses the Symfony AI beta hard on purpose. If you find a bug in a dependency, don't work around it silently: add the smallest reproduction under `upstream/` (see the existing ones) and say so in the pull request. The owner decides whether it is reported upstream.

## Code of conduct

Be kind and assume good intent. Critique code, not people.
