# Running Intakt's CI on your own runner

Intakt's own CI (`.github/workflows/ci.yml` and `.github/workflows/journeys.yml`) runs on GitHub's `ubuntu-24.04` runners unless you say otherwise. This page is for whoever maintains a copy of the Intakt repository and wants that CI on a machine of their own.

## Choosing the runner

Set the repository variable `CI_RUNS_ON` (Settings, Secrets and variables, Actions, Variables) to a JSON list of runner labels, for example:

```
["self-hosted","linux","x64","intakt-ci"]
```

These jobs then run on a runner with all of those labels: `check`, `journeys`, `changes`, `persistence` and `image` from `ci.yml`, `journeys` from `journeys.yml`, `image` from `release-image.yml` and `publish` from `ci-image.yml` (both push to `ghcr.io`, see [The published CI image](#the-published-ci-image)), and `publish` from `highlights.yml`. These jobs always run on GitHub's `ubuntu-24.04`: the `alarm` job of `journeys.yml` and the `label` job of `release-labels.yml`, because they use the `gh` command those runners have; the `latest` job of `release-image.yml`, because it uses `gh` and `docker buildx`; and the `build` job of `highlights.yml`, because it pulls from Docker Hub without a login, which GitHub's runners may do without a limit. Delete the variable to go back to GitHub's runners. Pull requests from forks never run, on either kind of runner.

`CI_RUNS_ON` does not move the `image` job of `image-arm64.yml`, which builds and starts the app image on arm64 on a push to main or by hand. It runs on GitHub's `ubuntu-24.04-arm` unless the variable `CI_ARM_RUNS_ON` holds a JSON label list (or one JSON string) of an arm64 runner, for example `["self-hosted","linux","ARM64"]`.

## A hosted runner service

A service that runs each job on a new VM and throws the VM away afterwards (for example Ubicloud) is set up the same way: install its GitHub App on the repository and put its runner label in `CI_RUNS_ON`, for example `["ubicloud-standard-4"]`. Such services register their runners as self-hosted, so also set the repository variable `CI_DISPOSABLE_RUNNER` to `true`: the jobs then allow user namespaces and lift AppArmor on that VM, as they do on GitHub's runners. Never set it for a machine you keep.

These services share their IP addresses among their customers, and Docker Hub allows only a few anonymous downloads per address, so a job can fail to fetch an image. Create a read-only access token on a Docker Hub account and store it, with the account name, as the repository secrets `DOCKERHUB_TOKEN` and `DOCKERHUB_USERNAME`; the jobs then log in before they download. The `check` and `persistence` jobs restore Composer's packages from GitHub's Actions cache, which only a push to main fills, so on a fresh VM they do not download every package from GitHub again; the first main run after a `composer.lock` change downloads them and saves the cache. A same-repository pull request's job can read the token, so give it no more than read access to public repositories.

The `journeys` job of `ci.yml` runs the journeys on every push to main and on every pull request that changes more than `docs/`, `tests/` or `upstream/`, or touches the journeys' test code (`tests/Journeys`, `tests/Support`, `tests/Core/Watcher/RecordedPullRequests.php`); when a journey fails, that run fails and no task is filed. `journeys.yml` also runs them on main every night; it and its `alarm` stay for now and are removed once the `journeys` job of `ci.yml` has been green on main for a week. To start a run yourself, use the "Run workflow" button of the `journeys` workflow on the Actions tab, or `gh workflow run journeys.yml`. When a journey fails, `alarm` files one urgent task, or updates the one already open.
When the run times out, `alarm` files or updates the timed-out task. A run you cancel files nothing.

## The runner must be ephemeral

A persistent self-hosted runner is not supported. The runner must start every job from a clean machine state and must have no network path to your other machines:

- **One job per machine state.** Register the runner with `--ephemeral`, so it takes exactly one job and exits, and start each job on a fresh VM or container, or on a snapshot rolled back before the job.
- **An isolated network.** The runner reaches GitHub and the registries the jobs download from (Docker Hub, GitHub's container registry `ghcr.io` for gitleaks and zizmor, Debian's packages and Composer's packages), and nothing on your local network. When `CI_RUNS_ON` points at the runner, the runner also runs the `image` job of `release-image.yml` and the `publish` job of `ci-image.yml`, which push to `ghcr.io` with a write token, and the `publish` job of `highlights.yml`, which deploys the highlights page with a `pages: write` token.

Why: a pull request from a branch of the repository runs its own version of the workflow and of `.github/ci/` on the runner, with access to the host's Docker daemon, which is as good as root on that host. On a machine that persists, such a run could leave something behind for a later job, main's included: for example an image tagged with the hash of main's `.github/ci/Dockerfile`, `composer.json` and `config/gates/php/check.ini`, which `.github/ci/build-image.sh` would then reuse instead of building, so main's `bin/check` would run in an image the pull request chose. The `publish` job of `ci-image.yml` reuses such an image the same way and would then push it to `ghcr.io` with its write token as the published CI image of that hash, which a build loop pulls and trusts. On a clean state for every job nothing a job does reaches the next one, and an image that exists when a job starts can only be one you put into the clean state yourself. Baking the CI image into that state saves the build; leaving it out is also fine, since the jobs then restore the copy a run on main cached, or build it.

## The published CI image

`ci-image.yml` publishes the CI image (built from `.github/ci/Dockerfile`) to GitHub's container registry as `ghcr.io/<owner>/<repository>-ci:<hash>`, lowercased, for example `ghcr.io/shinyredapples/intakt-ci:<hash>`. The hash is the one `.github/ci/build-image.sh --print-hash` prints, from the Dockerfile, `composer.json` and `config/gates/php/check.ini`, so a build loop can run `bin/check` in exactly CI's environment. It runs on a push to main that changes one of the image's inputs, and never for a pull request; an image already published for the hash is left as it is. To publish main's current hash yourself, use the "Run workflow" button of the `ci-image` workflow on the Actions tab, or `gh workflow run ci-image.yml`.

The package is private while the repository is. To pull it, log in to `ghcr.io` with a token that has `read:packages` and access to the package (for example `docker login ghcr.io --username <account> --password-stdin`, the token on stdin), then `docker pull ghcr.io/<owner>/<repository>-ci:<hash>`.

A baked image is used only while its tag matches that hash, so bake it again whenever main changes one of those files. The CI image installs a fixed list of PHP extensions, so a new `ext-*` entry in `composer.json` also needs a change to `.github/ci/Dockerfile`; without it the image build fails, naming the extension.

## Keeping the runner usable

The script that registers your ephemeral runner should do three things before each registration, so a runner that died mid-job does not block CI until someone cleans up by hand:

- **Delete an offline registration of its own name.** GitHub refuses the new session while a stale one exists ("A session for this runner already exists"), so the runner never comes online again.
- **Handle a registration that is still busy.** A busy registration of a runner that is gone means its job is orphaned on a dead runner and never finishes. Cancel that job's run, delete the registration, and re-run the cancelled jobs, unless a newer run of the same branch and event exists: the workflow's concurrency group (`ci-${{ github.ref }}`, with `cancel-in-progress`) would then let the re-run cancel the newer one.
- **End a cycle whose runner stays offline.** When the registered runner still shows as offline on GitHub three minutes after it started, stop that cycle and start the next one, instead of waiting for a runner that will never take a job.

## What the host needs

| Need | Why |
|---|---|
| Docker Engine (the `docker` command and a running daemon), with the runner's user allowed to use it (for example in the `docker` group) | the `check`, `persistence` and `journeys` jobs build or reuse the CI image and run the code in it; the `image` job builds the application image from the repository's `Dockerfile` on the host once, runs `docker/smoke-test.sh` against it, then `docker/verify-first-start.sh`, which creates a Docker volume and runs the same image on it, and when the change needs it `docker/verify-upgrade.sh`, which builds the base commit's image as well and starts both on copies of one volume; the `persistence` job starts a service container for each database engine |
| `bash`, `git`, `grep`, `sh`, `sha256sum`, `cut`, `mktemp` and `rm` | the `changes` job reads the diff on the host in a temporary folder; `build-image.sh` hashes the Dockerfile, `composer.json` and the check ini (the image loads that ini by itself) |
| Docker's `buildx` plugin, and network access to Docker Hub from the host | `docker/smoke-test.sh` (the `image` job) runs `docker buildx imagetools inspect` on the `Dockerfile`'s base image tag to print its digest, which asks the registry |
| `bash`, `curl`, `grep`, `awk`, `od`, `tr`, `dirname`, `env`, `timeout` and `sleep` | `docker/smoke-test.sh` (the `image` job) checks the started container over HTTP with `curl`, reads the `FROM` tag, the base image digest and the published port with `awk`, makes the run's random secrets with `od` and `tr`, and limits each wait |
| `openssl`, `bash` and `sleep` | `docker/verify-first-start.sh` (the `image` job) generates the random secrets for the started container with `openssl` on the host and waits with `sleep` until the container answers |
| Unprivileged user namespaces allowed (on Ubuntu 23.10 and later, `kernel.apparmor_restrict_unprivileged_userns=0`; elsewhere a non-zero `user.max_user_namespaces`) | the gate tests in `check` run processes in a new user namespace inside the container (a pull request that leaves the gate code alone skips them, but every push to main runs them); `journeys` runs each journey in one too, where `unshare` allows it |
| No AppArmor profile that blocks `mount` for the containers (on a host with AppArmor, Docker applies its `docker-default` profile, which does) | the gate tests mount a fresh `/proc`; `journeys` gets the same container settings; the workflows lift AppArmor only on GitHub's runners and with `CI_DISPOSABLE_RUNNER`, never on other runners of yours |

Both `journeys` jobs get the same seccomp profile as `check` (`.github/ci/seccomp-userns.json`). The workflows do not change any of this on a self-hosted runner: they set the sysctl and `apparmor=unconfined` only on GitHub's runners and with `CI_DISPOSABLE_RUNNER`, both thrown away after the job. Prepare the clean state so the host meets them.

## When it goes wrong

- **The `image` job built nothing:** the pull request changes only `docs/`, `tests/` or `upstream/`, so the job skips the image and still reports.
- **Jobs stay queued:** no online runner has every label in `CI_RUNS_ON`. Check the list against the runner's labels, and that an ephemeral runner is registered again after each job.
- **`check` fails with errors about namespaces or mounting `/proc`:** user namespaces are restricted or an AppArmor profile blocks mount on the host (see the table).
- **`permission denied` on the Docker socket:** the runner's user cannot use Docker.
