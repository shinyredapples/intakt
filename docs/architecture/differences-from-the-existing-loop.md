# Differences from the existing loop

The deliberate differences between Intakt and the loop it replaces. Each task that adds one extends this page ([plan](../plan.md), Replacing the existing loop).

## Job containers instead of system users and sandboxes

The existing loop separates its work with system users and sandboxes on the host. Intakt starts each job in a fresh container through the Docker Engine API ([Job containers](job-containers.md)):

| Concern | Existing loop | Intakt |
|---|---|---|
| Isolation of a session | a system user and a sandbox | a container with a read-only root and tmpfs areas |
| Home and caches | a wiped home per session | a fresh tmpfs home per job |
| Forge credential and signing key | withheld by user permissions | not in the job's environment, which is built from a fixed list |
| Egress | a deny-by-default sandbox | a per-job internal network and a proxy that allows the vendor and the registries; none for check and probe jobs |
| A runaway step | killed alone, the cause recorded | the container is killed by its memory limit and the ledger records `memory_killed` |
| Leftovers after a crash | cleaned up by the loop | labeled containers and networks removed when the next worker starts |
