# E1: the never-reporting round from `intakt:review` and `start_review` (#2001)

A running install on SQLite (`DATABASE_URL` naming a fresh SQLite file, migrated with `doctrine:migrations:migrate`), its web server on `localhost:7780` (`php -d variables_order=EGPCS -S localhost:7780 -t public`) and no worker, so no reviewer ever reports. The one reviewer is on `claude_code`; a scripted `claude` executable first on `PATH` stands in for the CLI and never answers, so no model provider is reached. `APP_SECRET`, `MERCURE_JWT_SECRET`, `CLAUDE_CODE_OAUTH_TOKEN` and `MCP_OWNER_TOKEN` were set to throwaway values, `MCP_OWNER_ACCESS=mcp_tool_write`. A throwaway uncommitted `config/services_dev.yaml` made the `clock` service a `MockClock`, so the 7200 second upper wait passed at once; it was removed afterwards. Paths are shortened to `$TMPDIR`.

Each call of `intakt:review` and each `start_review` opens a new round of its own (`ReviewChange::start()` dispatches `RunReviewRound`), so one round never has both outputs. To compare the two representations, both rounds are of the same change in the same database, and the owner MCP server's `review_status` reads each of them back by its full round id.

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

## The command: round 1

`php bin/console intakt:review sample-change --project=sample --repository=$TMPDIR/run/repo --base=main --task="Add a shipping cost" --format=json`, exit 11:

```json
{
    "round_id": "01a1db81-4b8c-7e48-b3ac-06f9e3fbd43f",
    "round": 1,
    "decision": "blocked",
    "exit_code": 11,
    "verdicts": [],
    "act_list": [],
    "tokens": { "calls": 0, "prompt": 0, "cache_read": 0, "cache_write": 0, "cached": 0, "completion": 0, "total": 0 },
    "cost": { "usd": 0, "unpriced": [] },
    "reasons": [
        "no outcome in time: the round did not end within 7200 seconds; if no worker runs, start one with \"messenger:consume async\", then review again"
    ]
}
```

## `start_review`: round 2

`initialize`, then a `tools/call` of `start_review` over HTTP to `/mcp/owner` with the bearer token, arguments `{"review":{"project":"sample","task":"Add a shipping cost","change":"sample-change","repository":"$TMPDIR/run/repo","base":"main"}}`; its `structuredContent`:

```json
{"roundId":"01a1db81-c97c-772a-a602-45e55a24556a","round":2,"decision":"blocked","act":[],"reasons":["no outcome in time: the round did not end within 7200 seconds; if no worker runs, start one with \"messenger:consume async\", then review again"],"end":"no_outcome_in_time"}
```

## Both rounds read back with `review_status`

In the same MCP session, `review_status` with each full round id:

```json
{"round":{"id":"01a1db81-4b8c-7e48-b3ac-06f9e3fbd43f","change":"sample-change","number":1},"state":"collecting","reviewers":[{"reviewer":{"role":"correctness","tier":"strong"},"state":"queued","failure":null}]}
{"round":{"id":"01a1db81-c97c-772a-a602-45e55a24556a","change":"sample-change","number":2},"state":"collecting","reviewers":[{"reviewer":{"role":"correctness","tier":"strong"},"state":"queued","failure":null}]}
```

Both rounds of `sample-change` stand in the same state (collecting, the reviewer still queued), and the command and the tool report them alike: decision `blocked`, an empty act list and the same reason, with exit 11 from the command and `end` `no_outcome_in_time` from the tool.
