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

The Notifier and Gate contracts are empty for now. The first implementation of each kind adds the methods it needs (see [Left to the builder](../design/08-left-to-the-builder.md)). The WorkSource contract has the methods of work intake (plan task T47), implemented by `Plugin/GitHubWorkSource`: the state of every waited-for issue comes from the tick's one listing of the forge, or from the stored state of an item that listing shows unchanged, never from one read per reference; only a complete, successful listing marks a waited-for issue closed; and a worker that will not take work this tick makes no forge call for intake (see [Work intake](work-intake.md) and [Choosing work](../design/05-architecture/03-choosing-work.md)). The Forge contract has the methods for reading a change and, apart from them, the one write so far; see [Forge contract and the local git forge](forge-contract.md).
