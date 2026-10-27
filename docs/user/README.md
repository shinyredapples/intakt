# Intakt user guide

For owners who run Intakt. Each feature gets a page here, written in the same change as the feature: what it does, how to set it up, and what to do when it goes wrong.

Nothing user-visible is built yet. The first pages arrive with the review loop (see [the plan](../plan.md)):

- Running the demo with `docker compose up` (planned)
- Configuring providers, API keys and the review panel (planned)
- Reviewing a pull request and reading the verdicts (planned)
- Connecting an agent through MCP (planned)

## Checking that an install boots

After `composer install`, `php bin/instance` starts Intakt on a local port with a scratch SQLite database, checks that it answers, stops it and removes everything it created. It needs no network. Descriptor 3 receives one line, `{"status":"ready"}` or `{"status":"failed","reason":"..."}`, for example:

```
php bin/instance 3>&1 >/dev/null
```

The exit code is 0 only when the status is `ready`. The server log goes to standard output.

## The ledger

Intakt records what happens in a review (review started, reviewer started, verdict received, judge done, usage recorded) as events in one SQLite table, `ledger_event`, in the order they happened. Create the table with:

```
php bin/console doctrine:migrations:migrate
```

There is no command to read the events back yet.
