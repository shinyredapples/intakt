## Module layout

```
src/
  Kernel.php
  Core/<Module>/        core areas (review, ledger, ...), added by the tasks that need them
  Plugin/<Kind>/        plugin kinds: Forge, Vendor, Notifier, WorkSource, Gate
    Contract/           the module's only public surface
    Domain/             entities and value objects
    UseCase/<Name>/     one use case and its doors (command, controller, handler, MCP tool)
    Infrastructure/     adapters
config/modules.php      the module list; a test keeps it equal to the directories
fixtures/sample-repository/   plain files for the demo, see sample-repository.md
```

The Notifier contract is empty for now. The first implementation of each kind adds the methods it needs (see [Left to the builder](../design/08-left-to-the-builder.md)). The Gate contract has the two methods the check job uses, `checkSteps()` (the shell steps to run in the job's clone, or the configured command instead) and `registryHosts()` (the hosts those steps may fetch dependencies from), and a third, `dependencyStep()` (the lock inputs and the clean install of the locked packages, apart from the generation of files made from the tree), which the check job reads through `CheckDependencies::leaseFor()` before a check of a gate that has one; `src/Plugin/PhpGate/` implements it; see [The pre-push check job](check-job.md). The WorkSource contract has the three methods of work intake (plan task T47); `Plugin/GitHubWorkSource` implements it for GitHub (`Infrastructure\GitHubWorkSource`, with its rules, its stored state and the pick), and nothing calls it yet, see [Work intake](work-intake.md). The state of every waited-for issue must come from the tick's one listing of the forge, or from the stored state of an item that listing shows unchanged, never from one read per reference (the one exception: a waited-for pull request that a complete listing no longer shows open is read on its own to learn whether it merged into main; a failed read stores nothing and is retried on the next tick, and after three failed reads in a row at most once a day, a read that finds it open again stores nothing and keeps the wait held, a stored "closed without a merge" answer is dropped as soon as a listing shows the pull request open again, and while a wait stays held on that answer the pull request is read again at most once a day); only a complete, successful listing may mark a waited-for issue closed; and a worker that will not take work this tick makes no forge call for intake ([Choosing work](../design/05-architecture/03-choosing-work.md)). The Forge contract has the methods for reading a change and, apart from them, the one write so far; see [Forge contract and the local git forge](forge-contract.md).

The Job contract's `JobSpec` may name a `JobKeptFolder`: a host folder the caller makes before the job and removes after it, mounted writable at `/kept` so a job can hand back more than its capped output directory holds. The runner neither makes nor removes it, and the dependency install job (`DependencyJobs`) is the job that uses one; see [Job containers](job-containers.md).
