# E1: `intakt:review` with a judge that rejects a P1 (#1891)

A running install on SQLite (`DATABASE_URL` naming a fresh SQLite file, migrated with `doctrine:migrations:migrate`), its web server on `localhost:7780` for the review MCP server (`php -d variables_order=EGPCS -S localhost:7780 -t public`) and one worker (`messenger:consume async`). Every tier of the project is on `claude_code`; a scripted `claude` executable put first on `PATH` stands in for the CLI, so no model provider is reached. `APP_SECRET`, `MERCURE_JWT_SECRET` and `CLAUDE_CODE_OAUTH_TOKEN` were set to throwaway values. Paths are shortened to `$TMPDIR`.

## Project configuration (`INTAKT_CONFIG`)

```yaml
projects:
    sample:
        tiers:
            strong: { provider: claude_code, model: sonnet }
            standard: { provider: claude_code, model: sonnet }
            light: { provider: claude_code, model: haiku }
        review:
            quorum: all
            reviewers:
                - { role: correctness, tier: strong, provider: claude_code, prompt: Check that the change does what the task asks., budget: 4 }
```

## The scripted `claude`

```php
#!/usr/bin/env php
<?php
// Scripted stand-in for the Claude Code CLI: the reviewer finds a P1 and a P3, the judge rejects every finding it is given.
$prompt = $argv[array_search('-p', $argv, true) + 1];
$log = getenv('SCRIPTED_LOG') ?: __DIR__.'/calls.log';

if (str_contains($prompt, 'The findings to judge:')) {
    $fence = substr($prompt, strpos($prompt, 'The findings to judge:'));
    preg_match_all('/\bF\d+\b/', $fence, $matches);
    $ids = array_values(array_unique($matches[0]));
    $answer = ['marks' => array_map(static fn (string $id): array => ['id' => $id, 'mark' => 'reject'], $ids)];
    file_put_contents($log, 'judge: '.json_encode($answer)."\n", FILE_APPEND);
} else {
    $finding = static fn (string $id, string $severity, int $line, string $text): array => ['id' => $id, 'severity' => $severity, 'file' => 'src/Cart.php', 'line' => $line, 'symbol' => 'Cart::total', 'text' => $text, 'fixable_by' => 'builder', 'repeat' => false, 'requirement' => 'task', 'evidence' => ['probe' => 'read src/Cart.php', 'output' => 'see line']];
    $answer = ['findings' => [$finding('F1', 'P1', 12, 'A cart of 100 or more loses its discount.'), $finding('F2', 'P3', 30, 'The name of a local variable is unclear.')], 'verdict' => 'changes', 'resolved' => [], 'follow_ups' => []];
    file_put_contents($log, 'reviewer: '.json_encode($answer)."\n", FILE_APPEND);
}

$text = json_encode($answer);
echo json_encode(['type' => 'system', 'subtype' => 'init', 'model' => 'sonnet', 'tools' => []]), "\n";
echo json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => $text, 'usage' => ['input_tokens' => 120, 'output_tokens' => 40, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]]), "\n";
```

## Text output

`php bin/console intakt:review sample-change --project=sample --repository=$TMPDIR/sample-repo --base=main --task="Add a total to the cart; a cart of 100 or more keeps its discount."`, exit 10:

```
changes (round 1)
strong reviewer: changes, 2 findings
act list: 1 findings
F1 P1 src/Cart.php:12: A cart of 100 or more loses its discount.
tokens: 2 calls, 240 prompt, 80 completion, 320 total
cost: unknown, no price for sonnet, haiku
```

The scripted CLI's log of the two calls (the reviewer, then the judge):

```
reviewer: {"findings":[{"id":"F1","severity":"P1",...},{"id":"F2","severity":"P3",...}],"verdict":"changes","resolved":[],"follow_ups":[]}
judge: {"marks":[{"id":"F1","mark":"reject"},{"id":"F2","mark":"reject"}]}
```

## JSON output

The same command with `--format=json` on a fresh database, exit 10:

```json
{
    "round_id": "01a1d78e-d89b-7f6e-91b0-82ab3c04b53c",
    "round": 1,
    "decision": "changes",
    "exit_code": 10,
    "verdicts": [
        {
            "tier": "strong",
            "decision": "changes",
            "findings": 2
        }
    ],
    "act_list": [
        {
            "id": "F1",
            "severity": "P1",
            "file": "src/Cart.php",
            "line": 12,
            "symbol": "Cart::total",
            "text": "A cart of 100 or more loses its discount.",
            "fixable_by": "builder"
        }
    ],
    "tokens": {
        "calls": 2,
        "prompt": 240,
        "cache_read": 0,
        "cache_write": 0,
        "cached": 0,
        "completion": 80,
        "total": 320
    },
    "cost": {
        "usd": null,
        "unpriced": [
            "sonnet",
            "haiku"
        ]
    },
    "reasons": []
}
```

The judge rejected both findings: the P1 `F1` stays on the act list in both outputs, the rejected P3 `F2` is on neither.

Both runs were repeated on the head that merges main (after `cache:clear` with `INTAKT_CONFIG` set), with the same text and JSON output and the same two calls; the round id above is from that run.
