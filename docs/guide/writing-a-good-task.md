# Writing a good task

A task is one issue the loop builds into one pull request. The builder reads it as written, so every line should say something the builder can act on or a reviewer can check.

## Evidence the builder can produce

Every Evidence item must be something the builder can produce inside its sandbox. The sandbox has no network beyond the model provider and the package registries, no access to the forge, and no real credentials.

Evidence only the owner or the orchestrator can produce is marked `(owner provides)`. It is not the builder's to satisfy. Without the mark, reviewers ask for the item round after round and the builder cannot deliver it.

The builder can produce, for example:

- a test run listing the cases, or one named test;
- the output of a console command on a local install (`bin/console doctrine:migrations:migrate` on SQLite);
- `bin/instance` and `bin/probe` runs, which work offline (the builder's sandbox refuses new user namespaces, so the builder runs `bin/probe` with `PROBE_ISOLATED=1`, which does not open the network: `bin/probe` first checks that only `lo` is visible and up, and refuses with exit code 2 if not; the output is valid Evidence);
- a call against `MockPlatformFactory` with a `ScriptedResponse`, or a recorded `HttpCassette`;
- the diff, for a change to documentation only.

Only the owner or the orchestrator can produce, for example:

- a CI run link (the builder has no forge access);
- a call to a real model provider with a real key;
- a webhook delivered by the forge, or a comment posted on it;
- a run on the owner's own install.

## Template

```markdown
<What changes and why, in a few sentences.>

Acceptance: <the observable conditions that make the task done>.

Evidence:
- <a test run or command output the builder can produce>
- <a CI run link or other item only the owner can produce> (owner provides)

Waits for #<n>
```

An item marked `(owner provides)` still belongs in the list: it tells reviewers what the owner checks before the merge.
