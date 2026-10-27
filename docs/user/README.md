# Intakt user guide

For owners who run Intakt. Each feature gets a page here, written in the same change as the feature: what it does, how to set it up, and what to do when it goes wrong.

Only the install check and the provider configuration below exist so far. The other pages arrive with the review loop (see [the plan](../plan.md)):

- Running the demo with `docker compose up` (planned)
- Configuring the review panel (planned; providers and keys are below)
- Reviewing a pull request and reading the verdicts (planned)
- Connecting an agent through MCP (planned)

## Checking that an install boots

After `composer install`, `php bin/instance` starts Intakt on a local port with a scratch SQLite database, checks that it answers, stops it and removes everything it created. It needs no network. Descriptor 3 receives one line, `{"status":"ready"}` or `{"status":"failed","reason":"..."}`, for example:

```
php bin/instance 3>&1 >/dev/null
```

The exit code is 0 only when the status is `ready`. The server log goes to standard output.

Descriptor 3 can be a pipe, a file or a memfd (an anonymous in-memory file, as a supervising program may pass). A memfd named exactly `opcache_lock` is not accepted, because PHP's OPcache uses that name for its own lock; any other name works. When descriptor 3 is not open, or is not accepted, no line is written and the exit code is 1.

## Running a probe against an install

`php bin/probe tests/Probe/NAME.php` boots the same scratch instance and runs one small PHPUnit test (a name ending in `Test.php`) or PHP script against it, with the network cut, so anything that reaches for the network fails. The script or test finds the instance's address in the environment variable `INTAKT_INSTANCE_URL`. Its output is printed, followed by a line `exit code: N`, and the command exits with that code. A probe still running after 60 seconds is stopped with exit code 124. The instance, its files and anything the probe started are removed afterwards. It needs Linux with `unshare` and `ip`.

## Configuring providers and API keys

Intakt knows three providers: `anthropic`, `openai` and `openai_compatible` (any endpoint that speaks the OpenAI API). Each reads its key from the environment, in `.env.local` or the real environment, never from a committed file:

| Provider | Variables it needs |
|---|---|
| `anthropic` | `ANTHROPIC_API_KEY` |
| `openai` | `OPENAI_API_KEY` |
| `openai_compatible` | `OPENAI_COMPATIBLE_BASE_URL` and `OPENAI_COMPATIBLE_API_KEY` |

A provider whose variables are not all set (or are empty) is switched off, and Intakt still starts. Each console command prints a line on standard error for every switched-off provider, for example `Provider "openai" is disabled: set OPENAI_API_KEY to enable it.` The same text is in the container parameter `intakt.ai.disabled_providers`. `php bin/console debug:config ai` lists the providers that are on. Whether a key is set is decided when the container is built, so after you add or remove a key, run `php bin/console cache:clear`. Which model serves which capability tier comes with the per-project configuration (planned).
