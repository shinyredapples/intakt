# Job containers

Plan task T44. The `Core/Job` module runs one job (a build, a check, a probe) in a fresh container, through the Docker Engine API over its socket. No other part of Intakt is wired to it yet: the build and review flows start using it in later tasks (T45 and after). Other modules use only `src/Core/Job/Contract/`: `JobRunner::run(JobSpec): JobOutcome`.

## What a job gets

`DockerJobRunner` creates the container from `JobSpec` (`Infrastructure/JobContainerBody.php`):

- a read-only root, and fresh tmpfs areas for `/work`, `/home/job` (the job's `HOME`) and `/tmp`, so no session inherits another's home or caches; they go with the container;
- each input mounted read-only at its own path; the job's own output directory, mounted at `/output`, is the only writable mount, so a job cannot write outside it or read another job's output (nothing of another job is mounted);
- a memory limit with no swap, a CPU limit, a process limit, all capabilities dropped and `no-new-privileges`;
- an environment built from a fixed list (`HOME`, `INTAKT_JOB_ID`, `INTAKT_OUTPUT_DIR`, `INTAKT_INPUTS`, and with egress `INTAKT_VENDOR_HOST` and the proxy variables) and never inherited, so a forge token, a signing key or a vendor API key of the orchestrator never reaches a job.

`JobSpec` refuses an unsafe job id (it becomes a container, network and label name), an input mounted over a place the container uses itself (`JobPaths`), an input that holds or sits inside the output directory, a limit of zero, and hosts that are not plain names.

## Egress

- `EgressPolicy::None` (check and probe jobs): network mode `none`, no proxy.
- `EgressPolicy::Allowlist`: the runner creates an internal network for the job and a proxy sidecar (`Infrastructure/EgressProxy.php`, image from `docker/job-proxy/`, a tinyproxy configured from `INTAKT_PROXY_ALLOW`). The sidecar is the only member with a way out; the job reaches it as `proxy` on port 8888 through `HTTPS_PROXY`. The allowlist is the vendor host first, then the registry hosts. A request for any other host is refused and logged by the proxy; after the job ends the runner reads the proxy's log and returns the refusal lines in `JobOutcome::$egressRefusals` (at most 100, each cut to 300 characters).

The proxy image is built from `docker/job-proxy/` and named by the parameter `JobServices::PROXY_IMAGE` (`config/services.yaml`). The tinyproxy settings in that image have not been run against a daemon in the build sandbox, which has none; only the runner's use of it is tested, against a fake Engine.

## Start check and reduced mode

The container's entrypoint is `Infrastructure/start-check.sh`, then the job's command. The script writes a probe file into `/output` and reads it back, checks that each input is readable, and with egress asks the vendor host through the proxy (it needs `curl` in the image). It prints one `start check: <item> ok|failed` line per item to the job's log, writes the items to `/output/.start-check`, and when any failed prints `REDUCED MODE: ...` and sets `INTAKT_REDUCED_MODE=1` for the command.

After exit `StartCheckReader` reads that file into a `StartCheckReport`. The file is written by the job into a directory it controls, so it is untrusted: a link in its place is not followed, a file over 64 KiB is not read, a reason is cut to 200 printable characters, and an expected item the job did not report counts as failed. `StartCheckReport::reduced()` is true when any item failed.

## End cause and cleanup

- The runner waits for the container for `JobSpec::$timeoutSeconds`; past it the container is killed and the cause is `TimedOut`. Otherwise the cause is `MemoryKilled` when the engine's `State.OOMKilled` is set (never guessed from exit 137) and `Exited` if not.
- `JobLedger` appends a `job_ended` ledger event with the job id, cause, exit code, whether the job ran reduced and the number of egress refusals. A refused append throws, after cleanup.
- The container, its proxy and its network are removed in a `finally` (never `AutoRemove`, because the inspect data and the output are read after exit). One failed removal is logged as a warning and does not stop the others.
- A run holds a Symfony Lock (`JobLocks`, key `job-<id>`) from start to end; a second run of the same id is refused. After an orchestrator crash the operating system frees the file lock, and `UseCase/SweepOrphanedJobs` (a listener on Messenger's `WorkerStartedEvent`) removes every container and network labeled `intakt.job` whose job lock it can take without waiting. A job another worker still runs holds its lock and is left alone.

## Not built here

The job image (T45), the vendor login mount and its read-only protection, hand-back of a bundle, and reading the output directory safely (a job controls what it writes there, links included) belong to later tasks. Differences from the existing loop: [differences-from-the-existing-loop.md](differences-from-the-existing-loop.md). Tests: `tests/Core/Job/`.
