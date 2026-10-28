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

`php bin/probe tests/Probe/NAME.php` boots the same scratch instance and runs one small PHPUnit test (a name ending in `Test.php`) or PHP script against it, with the network cut, so anything that reaches for the network fails. The script or test finds the instance's address in the environment variable `INTAKT_INSTANCE_URL`. Its output is printed, followed by a line `exit code: N`, and the command exits with that code. A probe still running after 60 seconds is stopped with exit code 124. The instance, its files and anything the probe started are removed afterwards. It needs Linux with `unshare` and `ip`, which cut the network for the run.

A caller that already runs `bin/probe` without network access and in its own process namespace (as the build loop's sandbox does) sets the environment variable `PROBE_ISOLATED=1`. `bin/probe` then starts the instance directly and needs neither `unshare` nor `ip`, but first checks that the run really is offline: when `/proc/net/dev` cannot be read or lists something unexpected, when it shows a network interface other than `lo`, or when `lo` is down, it prints the reason and exits with code 2 before anything starts. The caller is responsible for ending whatever the probe leaves running. Any other value, or no value, keeps the `unshare` way.
