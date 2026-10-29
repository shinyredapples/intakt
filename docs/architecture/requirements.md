# Requirements and external commands

Every program outside PHP's own code that Intakt or its checks call, who calls it, and whether you need it. A change that adds a new external command adds it here in the same change.

## To run Intakt

Only **Docker or Podman**. The container image ships everything below that Intakt needs at runtime (planned with the image, plan task T22).

## To work on Intakt

| Command | Called by | What for | Needed | Version | In the image |
|---|---|---|---|---|---|
| `php` | everything; the upstream reproduction runner, `bin/instance` and `bin/probe` run it as `PHP_BINARY` | the application, reproductions, booting an instance, probes | yes | 8.5.12 or later (CI refuses older: PHP2Shell) | yes |
| `composer` | `composer install`; the `composer audit` gate | installing dependencies, the security advisory check | yes | 2.x | development only |
| `git` | the local git forge (`src/Plugin/LocalGitForge/`); the commit-subject, history-secrets, long-dashes and scope gates | reading changes and history | yes | a current release | yes |
| `sh` | `bin/instance`, `bin/probe`, the gate runner | closing an inherited file descriptor before handing over; bringing up loopback | yes (POSIX) | any | yes |
| `gitleaks` | the secrets gates (files and history) | secret scanning | for `bin/check` | exactly 8.30.1, or point `INTAKT_GITLEAKS` at that version | development only |
| `unshare` (util-linux) | the gate runner (contained test runs), `bin/probe` | running tests and probes in their own namespaces | for `bin/check` and probes | with user namespaces enabled | development only |
| `env` (coreutils) | the gitleaks gate | running gitleaks in the scanned directory (`env --chdir`) | for `bin/check` | coreutils 8.28 or later | development only |
| `true` | the gate runner | probing once whether `unshare` may create namespaces | for `bin/check` | any | development only |
| `setsid` (util-linux) | the gate runner | putting a contained run in its own session, so it can be stopped as a whole | for `bin/check` | any | development only |
| `ip` (iproute2) | `bin/probe` | bringing loopback up inside a fresh network namespace | for probes | any | development only |
| `vendor/bin/phpunit` and the other Composer tools | the gates, `bin/probe` | tests, static analysis, code style | yes | as locked in `composer.lock` | development only |

`unshare`, `setsid` and `ip` are Linux tools, so the full `bin/check` runs on Linux (or in the development container, plan task T35). On another system, run it in that container.

## Coming with planned parts

| Command | Where | What for |
|---|---|---|
| `git` | every job image | builders and reviewers work in a git checkout |
| the AI companies' command-line tools (Claude Code, Codex) | every job image | running builders and reviewers on a subscription |
| the Docker or Podman API | the orchestrator | starting job containers and sidecars, through the socket (an API, not a command) |

## How Intakt calls them

- **Through Symfony Process with an argument list, never a shell string:** the local git forge, the gates (through their runner) and the upstream reproduction runner. `bin/instance` and `bin/probe` start their processes with PHP's `proc_open` and an argument list; where they use `sh`, the command after it is passed as separate arguments.
- **With a time limit** on every call made through Process.
- **The local git forge** (`src/Plugin/LocalGitForge/`) runs `git` with `--no-replace-objects` and literal pathspecs, and without the `GIT_*` variables of the calling environment. The gates call plain `git` with the caller's environment for now; hardening every `git` call the same way is a planned task.
