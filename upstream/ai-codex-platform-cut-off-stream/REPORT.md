# [Codex] A turn that never completed is returned as a complete answer when not streaming

Filed as pull request symfony/ai#2889 (merged 2026-11-06, not released yet). Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

## What happens

`codex exec --json` ends a finished turn with a `turn.completed` event, which also carries the usage. `RawProcessResult::getData()` returns the last `agent_message` whether or not that event arrived, so a stream that was cut off after a message reads as a complete answer, without usage; the streamed path yields the text and ends without an error the same way. A real `codex exec` exits with 1 on failed and interrupted turns, and the bridge already throws there; the case left is an exit with 0 and no `turn.completed`, when the CLI's in-process event channel closes before the turn ends. `reproduce.php` stands in for it with `printf` (exit 0), converts output that stops after the agent message, once plain and once streamed, and prints:

```
not streamed: Symfony\AI\Platform\Result\TextResult: "{"verdict":"approve"}"
A turn that never completed passes for a complete answer
streamed: "{"verdict":"approve"}"
A streamed turn that never completed ends without an error
```

## Expected

`IncompleteStreamException` when the output holds no `turn.completed` (or `turn.failed`) event, so a caller can tell a cut-off turn from a finished one.

## Effect in Intakt

`src/Core/Ai/Infrastructure/CompletedCodexTurn.php` wraps the bridge's raw result and raises `IncompleteStreamException` when an agent message comes without the usage of `turn.completed`.

## Upstream status

- **Checked:** 2026-11-14. Ran against the default branch `main` of `symfony/ai` (c4cb5da): the reproduction passes. Not streamed, the turn fails with `RuntimeException` (`Codex CLI turn ended before turn.completed.`); streamed, with `IncompleteStreamException`. `reproduce.php` accepts both, the `RuntimeException` only with that message. The newest release, v1.0.0-BETA1, still fails: `php bin/console intakt:upstream:run` prints `still fails  ai-codex-platform-cut-off-stream`.
- **Pull requests and commits:** filed as pull request symfony/ai#2889 by Intakt (`[Platform][Codex] Refuse a turn without turn.completed`, merged 2026-11-06 as a1c443fe, merge d23c5adf). `git log v1.0.0-BETA1..origin/main -- src/platform/src/Bridge/Codex/` also lists 1fc422e4 and 32056f8e (#2875), 98a1dd01 and 642cd818, none about this bug; #2713 (JSON Lines on Windows) is still open.
- **Status:** `filed as pull request` https://github.com/symfony/ai/pull/2889, merged 2026-11-06 on the development branch (recorded 2026-11-20); the first release after v1.0.0-BETA1 will carry it, and none is out yet. Once it is: record that release here, and revisit `src/Core/Ai/Infrastructure/CompletedCodexTurn.php`. Checked 2026-11-22: no `symfony/ai` release after v1.0.0-BETA1 yet (the newest tag is still v1.0.0-BETA1).
