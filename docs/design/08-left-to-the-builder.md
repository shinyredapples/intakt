# Left to the Developer (formerly left to the builder)

The design fixes behavior, not shapes. These are the Developer's call, made in the task that first needs them, recorded in `docs/architecture/` and open to review:

- The method signatures of the plugin contracts. The design gives each kind a sketch ([Plugins](05-architecture/02-plugins.md)); the first implementation of a kind turns the sketch into an interface with only the methods it uses.
- Table and entity layout for the ledger, the queue and the memory, as long as the persistence tests pass on SQLite, MySQL, MariaDB and PostgreSQL.
- The format and loading of the owner's config and of the project file, within the rule that the project file may set delivery policy and the runtime memory limit only ([Security model](04-constraints.md#security-model)).
- Class and module names inside the module layout (`src/<Area>/<Module>/` with a `Contract` namespace; see `AGENTS.md`).
- Which Symfony AI component serves which role, as long as every component the design names is used for a real purpose ([Platform](04-constraints.md#platform-decided-2026-10-26)).
- Prompt wording for the Code reviewers and their verdict step (formerly the judge), as long as the typed verdict is enforced.
