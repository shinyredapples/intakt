# Intakt user guide

For owners who run Intakt. Each feature gets a page here, written in the same change as the feature: what it does, how to set it up, and what to do when it goes wrong.

Only the install check and the provider configuration below exist so far. The other pages arrive with the review loop (see [the plan](../plan.md)):

- Running the demo with `docker compose up` (planned)
- Configuring the review panel (planned; providers and keys are below)
- Reviewing a pull request and reading the verdicts (planned)
- Connecting an agent through MCP (planned)

## Reading a change from a local repository

Intakt can treat a branch of a local git repository as a pull request: the branch is the change and the repository's base branch is what it is compared with. It reads the branch's latest commit, the diff against the point where the branch left the base, the list of changed files and any file as it was at that commit (by its exact name, even one starting with `:`), ignoring git's replacement refs (`git replace`), which could otherwise show other content under the same commit. It notices when the branch has moved to a newer commit since it was read. It only reads; it never changes the repository. There is no command for it yet.

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

Intakt knows three providers: `anthropic`, `openai` and `openai_compatible` (any endpoint that speaks the OpenAI API). Each reads its key from Symfony secrets (`php bin/console secrets:set ANTHROPIC_API_KEY`) or from the environment (`.env.local` or the real environment), never from a plain committed file; a non-empty environment value wins over the secret:

| Provider | Variables it needs |
|---|---|
| `anthropic` | `ANTHROPIC_API_KEY` |
| `openai` | `OPENAI_API_KEY` |
| `openai_compatible` | `OPENAI_COMPATIBLE_BASE_URL` and `OPENAI_COMPATIBLE_API_KEY` |

A provider whose variables are not all set (empty counts as not set), neither in the environment nor in the secrets, is switched off, and Intakt still starts. Each console command prints a line on standard error for every switched-off provider, for example `Provider "openai" is disabled: set OPENAI_API_KEY to enable it.` The same text is in the container parameter `intakt.ai.disabled_providers`. `php bin/console debug:config ai` lists the providers that are on. Whether a key is set is decided when the container is built, so after you add or remove a key, run `php bin/console cache:clear`. Which model serves which capability tier comes with the per-project configuration (planned).

## The ledger

Intakt has a ledger that can store review events (review started, reviewer started, verdict received, judge done, usage recorded) in one SQLite table, `ledger_event`, and read them back in the order they were stored, even if the system clock is set back in between. Each event gets an id that carries the time it was stored (a UUID version 7) and keeps the moment it was stored in UTC, and reads it back the same whatever time zone PHP is set to. Nothing writes to it yet. Create the table with:

```
php bin/console doctrine:migrations:migrate
```

There is no command to read the events back yet.

## Checking a new Symfony AI release against the known bugs

`php bin/console intakt:upstream:run` runs every reproduction in `upstream/` and prints the installed Symfony AI versions, then `passes` (the bug seems fixed) or `still fails` for each reproduction, and a count. Add `-v` to see why a reproduction fails. The exit code is 1 while any reproduction still fails. A reproduction counts as `passes` only when its PHPUnit tests ran and asserted something, or when its `reproduce.php` script exits with 0 and prints `REPRODUCTION PASSES` as its last line. One without tests or script, with a skipped or incomplete test, or that runs longer than 5 minutes is reported as `still fails`, and the other reproductions still run. A folder that does not exist or cannot be read is an error (exit code 2).
