# Running Intakt's CI on your own runner

Intakt's own CI (`.github/workflows/ci.yml`) runs on GitHub's `ubuntu-24.04` runners unless you say otherwise. This page is for whoever maintains a copy of the Intakt repository and wants that CI on a machine of their own.

## Choosing the runner

Set the repository variable `CI_RUNS_ON` (Settings, Secrets and variables, Actions, Variables) to a JSON list of runner labels, for example:

```
["self-hosted","linux","x64","intakt-ci"]
```

Every job (`check`, `changes` and `persistence`) then runs on a runner with all of those labels. Delete the variable to go back to GitHub's runners. Pull requests from forks never run, on either kind of runner.

## The runner must be ephemeral

A persistent self-hosted runner is not supported. The runner must start every job from a clean machine state and must have no network path to your other machines:

- **One job per machine state.** Register the runner with `--ephemeral`, so it takes exactly one job and exits, and start each job on a fresh VM or container, or on a snapshot rolled back before the job.
- **An isolated network.** The runner reaches GitHub and the registries the jobs download from (Docker Hub, Debian's packages, Composer's packages and getcomposer.org), and nothing on your local network.

Why: a pull request from a branch of the repository runs its own version of the workflow and of `.github/ci/` on the runner, with access to the host's Docker daemon, which is as good as root on that host. On a machine that persists, such a run could leave something behind for a later job, main's included: for example an image tagged with the hash of main's `.github/ci/Dockerfile`, which `.github/ci/build-image.sh` would then reuse instead of building, so main's `bin/check` would run in an image the pull request chose. On a clean state for every job nothing a job does reaches the next one, and an image that exists when a job starts can only be one you put into the clean state yourself. Baking the CI image into that state saves the build; leaving it out is also fine, since the script then builds it.

## What the host needs

| Need | Why |
|---|---|
| Docker Engine (the `docker` command and a running daemon), with the runner's user allowed to use it (for example in the `docker` group) | every job builds or reuses the CI image and runs the code in it; the `persistence` jobs start their database as a service container |
| `bash`, `git`, `grep`, `sh`, `sha256sum` and `cut` | the `changes` job reads the diff on the host; `build-image.sh` hashes the Dockerfile |
| Unprivileged user namespaces allowed (on Ubuntu 23.10 and later, `kernel.apparmor_restrict_unprivileged_userns=0`; elsewhere a non-zero `user.max_user_namespaces`) | the gate tests in `check` run processes in a new user namespace inside the container |
| No AppArmor profile that blocks `mount` for the containers (on a host with AppArmor, Docker applies its `docker-default` profile, which does) | the gate tests mount a fresh `/proc`; the workflow lifts AppArmor only on GitHub's runners, never on yours |

The workflow does not change any of this on a self-hosted runner: it sets the sysctl and `apparmor=unconfined` only on GitHub's runners, which are thrown away after the job. Prepare the clean state so the host meets them.

## When it goes wrong

- **Jobs stay queued:** no online runner has every label in `CI_RUNS_ON`. Check the list against the runner's labels, and that an ephemeral runner is registered again after each job.
- **`check` fails with errors about namespaces or mounting `/proc`:** user namespaces are restricted or an AppArmor profile blocks mount on the host (see the table).
- **`permission denied` on the Docker socket:** the runner's user cannot use Docker.
