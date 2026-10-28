# Intakt user guide

For owners who run Intakt. Each feature gets a page here, written in the same change as the feature: what it does, how to set it up, and what to do when it goes wrong.

Nothing user-visible is built yet. The first pages arrive with the review loop (see [the plan](../plan.md)):

- Running the demo with `docker compose up` (planned)
- Configuring providers, API keys and the review panel (planned)
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

## Looking inside the development install with Mate

On a development install (`composer install` with the dev packages), `vendor/bin/mate` answers questions about the running app from its own container and environment files instead of from reading the code. It is not part of a production install: `composer install --no-dev` leaves it out.

- `vendor/bin/mate tools:list` lists the tools.
- `vendor/bin/mate tools:inspect TOOL` shows a tool's parameters.
- `vendor/bin/mate tools:call TOOL --PARAM=VALUE` runs one; add `--format=json` for machine-readable output.

The useful tools today are `symfony-services` and `symfony-service-detail` (which services the container holds and how one is wired), `symfony-dotenv-check` (which `.env*` file declares a variable and whether it resolves, with the value masked) and `server-info` (PHP version, system and extensions). The profiler tools are listed too, but Intakt does not turn on Symfony's profiler, so they find no profiles.

If Mate stops with a PHP version mismatch, it was started with a PHP other than 8.5 (the version `mate/config.php` pins); run it with the PHP 8.5 the app uses. If a container tool reports "No compiled container found", run `bin/console cache:warmup` first so the dev container exists.

## Checking a new Symfony AI release against the known bugs

`php bin/console intakt:upstream:run` runs every reproduction in `upstream/` and prints the installed Symfony AI versions, then `passes` (the bug seems fixed) or `still fails` for each reproduction, and a count. Add `-v` to see why a reproduction fails. The exit code is 1 while any reproduction still fails. A reproduction counts as `passes` only when its PHPUnit tests ran and asserted something, or when its `reproduce.php` script exits with 0 and prints `REPRODUCTION PASSES` as its last line. One without tests or script, with a skipped or incomplete test, or that runs longer than 5 minutes is reported as `still fails`, and the other reproductions still run. A folder that does not exist or cannot be read is an error (exit code 2).
