# Differences from the earlier loop

The earlier loop is the build loop that developed Intakt: the owner's own build loop, which builds, reviews and merges this repository's pull requests until Intakt takes over.

Intakt is to replace that loop for the owner's own use ([plan](../plan.md#replacing-the-earlier-loop)). The loop is a reference, not a source: where Intakt deliberately does a thing another way, the difference is listed here, with the reason and where it is built. Each task that adds a deliberate difference adds it to this page; the parity list of behaviors that must stay the same is in [Parity](parity.md).

## Waits for notes only in the task text

- **The loop:** a `Waits for` note counts in the task text of a trusted author and also in a trusted author's comment (its `test_unblock.py`).
- **Intakt:** `Waits for` lines are read from the task text only, and only when the task was written and last edited by an account with write access; comments are never read for them. A line may sit in a blockquote or a list and carry emphasis around the words (`> - **Waits for:** #9`); a note after the `#n` list may hold no digit of any script, a line whose list cannot be read (`Waits for #9 then 10`) holds the task as an unreadable wait, and a line inside an `<!-- external -->` block counts for nothing. The rules are listed in [Work intake](work-intake.md#wait-lines-domainissuereferences). Built in `Plugin/GitHubWorkSource` (`IssueReferences`, `IntakeRules`).
- **Why:** the task text is the one place a wait is written and kept, so what a task waits for is read from one place and changes only when the task is edited.

## Job containers instead of system users and sandboxes

- **The loop:** builders share a Unix user and a long-lived worktree, so every piece of state they leave (dotfiles, git config, caches and the like) has to be reset by hand, and each reset found by review became one more denylist item ([principles](../design/02-principles.md)).
- **Intakt:** every job (a build, a check, a review) runs in a fresh container through the Docker Engine API, with a read-only root, a work area on tmpfs that is its home and holds its caches, inputs mounted read-only, one output directory read after exit, its own memory and CPU limit and no environment from the orchestrator, so no forge credential or signing key. The container, its output directory and, after a crash, whatever a crashed run left are removed by plain code. Built in `Core/Job` ([Job containers](job-containers.md)); no lane starts a job through it yet.
- **Why:** nothing a job writes survives it except what it hands back, so there is no state to reset and no denylist to grow.

## No job image without the project's PHP

- **The loop:** a project without its PHP record gets no probes rather than the system's PHP (its `test_entry_harness.py`).
- **Intakt:** there is no system PHP to fall back to: a project's job image comes from its devcontainer, its Dockerfile or `composer.json`, and when `composer.json` names no PHP version no image is built at all. Every built image is asked for its PHP version and extensions, and one that does not match `composer.json` is refused. Built in `Core/Job` ([Job images](job-images.md)); `intakt:build:run` runs a [build job](build-job.md) in the image its caller names, and the build lane that runs builds in the checked image is not built yet.
- **Why:** the PHP a job runs is a property of the project's image, so it is checked once when the image is built instead of in each step that runs PHP.

## An egress allowlist per job through its own proxy

- **The loop:** egress is denied by default (its `test_review_gpt_egress.py`).
- **Intakt:** a probe job has no network at all; the PHP gate's check job may reach the package registry hosts the gate names ([Check job](check-job.md)) and nothing else; any other job names the exact hosts it may reach, sits on an internal network of its own, and its only way out is a proxy of its own that allows `CONNECT` to port 443 of those hosts and nothing else (it does not check that TLS runs inside the tunnel); each refused request is recorded in the ledger (`job_egress_refused`). Built in `Core/Job` ([Egress](job-containers.md#egress)). The parity item for this behavior stays open until it has run on a host with a Docker daemon.
- **Why:** the allowlist is a property of the job, not of the machine or a user account, so two jobs never share one.

## Room learned per account from the first reading

- **The loop:** learned on 2026-11-13, at full load, that two accounts of one vendor differ: on one a five-hour window lasted five builders about 90 minutes, on the other only the weekly window counted. Its planner was fixed that day.
- **Intakt:** the planner learns each account's room from that account's own readings from the start and never assumes two accounts of one vendor are alike ([accounts differ](../design/05-architecture/06-budget-and-model-planner.md#accounts-differ)). Planned in T81; nothing of it is built yet.
- **Why:** a rule found by running out of room is designed in, so Intakt does not learn it again.

## A start check and a reduced mode instead of failing halfway

- **Intakt:** every job first checks that what it was told it can do works (a probe file written and read back in its output directory and work area, each input readable, each allowlisted host reachable through its proxy). A failed check is logged, recorded in the ledger with its reason (`job_start_check_failed`), and the job runs in its reduced mode, told so through `INTAKT_REDUCED_MODE`. Built in `docker/job/start-check.sh` and `Core/Job` ([Start check](job-containers.md#start-check)).
- **Why:** a broken mount or a closed route is named at the start, with its reason, instead of failing silently halfway ([overview](../design/05-architecture/01-overview.md)).
