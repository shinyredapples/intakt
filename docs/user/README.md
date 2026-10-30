# Intakt user guide

For owners who run Intakt. Each feature gets a page here, written in the same change as the feature: what it does, how to set it up, and what to do when it goes wrong.

Only the install check and the provider configuration below exist so far. The other pages arrive with the review loop (see [the plan](../plan.md)):

- Running the demo with `docker compose up` (planned)
- Choosing a review panel by the size or paths of a change, and the dry run `intakt:review:panel` (see below). A project's panel and its quorum policy (`all`: every reviewer approves; `majority`: more than half approve; `one-strong-plus-one`: at least two approve, one of them a strong reviewer) are set in the configuration file (see below), but nothing runs the panel yet.
- Reviewing a pull request and reading the verdicts (planned)
- Connecting an agent through MCP (planned)

For whoever maintains a copy of the Intakt repository: [Running Intakt's CI on your own runner](self-hosted-ci.md).

## Reading a change from a local repository

Intakt can treat a branch of a local git repository as a pull request: the branch is the change and the repository's base branch is what it is compared with. It reads the branch's latest commit, the diff against the point where the branch left the base, the list of changed files and any file as it was at that commit (by its exact name, even one starting with `:`), ignoring git's replacement refs (`git replace`) and graft file (`.git/info/grafts`), which could otherwise show other content under the same commit or hide commits. It reads the repository with its own git settings: the repository's configuration and attributes cannot run a program (a hook, a filter, a diff or signature tool, or a fetch from a remote the repository names for an object it lacks: such an object is an error, never fetched) or change what is read, every file in the diff is shown as text, and the git configuration of the user running Intakt (`~/.gitconfig`, even a broken one) is not read. A repository whose `.git/info/attributes` file exists cannot be read, because that file could hide a change and git offers no way to ignore it: remove it first. It notices when the branch has moved to a newer commit since it was read. A name that is not a valid branch name (such as `../tags/v1`) is treated like a branch that does not exist. It only reads; it never changes the repository. `php bin/console intakt:change:summarize <branch>` prints what it reads (see [Summarizing a branch](#summarizing-a-branch)); for an invalid name it prints the same error as for a missing branch and exits with 1.

## Checking that an install boots

After `composer install`, `php bin/instance` starts Intakt on a local port with a scratch SQLite database, checks that it answers, stops it and removes everything it created. Without `TMPDIR` it keeps its scratch files in `var/`; a `var/` folder that the run created is removed again when it is empty, and one that existed before is left in place. If it cannot create its scratch folder, the reason names the cause, for example `cannot create the scratch directory: mkdir(): Permission denied`, or `unknown reason` when the system gave none. It needs no network. Descriptor 3 receives one line, `{"status":"ready"}` or `{"status":"failed","reason":"..."}`, for example:

```
php bin/instance 3>&1 >/dev/null
```

The exit code is 0 only when the status is `ready`. The server log goes to standard output.

Descriptor 3 can be a pipe, a file or a memfd (an anonymous in-memory file, as a supervising program may pass). A memfd named exactly `opcache_lock` is not accepted, because PHP's OPcache uses that name for its own lock; any other name works. When descriptor 3 is not open, or is not accepted, no line is written and the exit code is 1.

## When `bin/check` reports a stateful service

Intakt keeps one process alive across many requests and messages, so a service that keeps a value in a property would carry it into the next request. The `phpstan` gate of `bin/check` therefore reports a finding `intakt.statefulService` for every concrete class under `src/` with a property which is not `readonly`, including properties from a parent class or a trait under `src/`, and static ones, because the container registers every class under `src/` as a service. A property a vendor base class declares, such as those of Symfony's `Command` or `AbstractController`, is framework wiring rather than request state and is not reported. A virtual property, a hooked property without a backing store such as one with only a `get` hook that computes its value from other properties, keeps no value and is not reported either; a hooked property with a backing store is. The finding names the property. To resolve it, do one of:

- make the property, or the whole class, `readonly`;
- implement `Symfony\Contracts\Service\ResetInterface` and clear the property in `reset()`, which Symfony calls between requests and messages;
- if the class is a value object or a data transfer object rather than a service (a console input object for `#[MapInput]` included), mark it with Symfony's `#[Exclude]` attribute so the container does not register it. An `#[Argument]` or `#[Option]` property alone does not exempt a class, because the container still registers it.

Doctrine entities, embeddables, mapped superclasses and column types, and exceptions are not services and are never reported. Neither is the kernel, which performs the reset, nor any class outside `src/` (tests, gate code, scripts), which the container never registers.

## When `bin/check` reports a flag parameter

A boolean parameter that switches what a function does hides two behaviors behind one name, so the `phpstan` gate of `bin/check` reports a finding `intakt.flagParameter` for every parameter typed `bool`, `true` or `false` (also nullable or in a union) of a function, method, closure or constructor, except in `tests/`. The finding names the parameter. A `readonly` service is reported like any other. To resolve it, do one of:

- replace the boolean with a backed enum whose cases name the modes;
- split the method in two, one per behavior;
- if the boolean is data of a value object, make the class `readonly`, mark it with Symfony's `#[Exclude]` attribute (Doctrine entities, embeddables and mapped superclasses count as excluded too) and keep the boolean as it is, as a promoted constructor property or assigned unchanged to a property in the constructor body. Only such a constructor parameter passes, and only when the constructor body does nothing but assign variables to properties, one plain `$this->name = $variable;` per statement: a promoted boolean must appear nowhere in the body, and a stored one exactly once, as the variable of one of those assignments. Any other statement in the body (a condition, a call, `new`, `eval`, `include`, a `parent::__construct()` call) could read the boolean, so every boolean of that constructor is reported then, even in a value object, while the class's other methods may read the stored property, and the exemption follows the attribute, not the folder, so an unmarked `readonly` class under `bin/` or `config/gates/src/` is reported too.

## GitHub's test merge and the `commits` gate

On a pull request, GitHub checks out a merge of the branch into the base branch, with a subject such as `Merge <sha> into <sha>`, which is not a Conventional Commit. CI names that merge and the pull request's head with `--test-merge=<merge>:<head>`, and the `commits` gate skips it when its second parent is the pull request's head and its first parent is on the base branch, also when the base branch moved after the pull request's last update. Every other commit, merges included, still needs a conforming subject.

## Running a probe against an install

`php bin/probe tests/Probe/NAME.php` boots the same scratch instance and runs one small PHPUnit test (a name ending in `Test.php`) or PHP script against it, with the network cut, so anything that reaches for the network fails. The script or test finds the instance's address in the environment variable `INTAKT_INSTANCE_URL`. Its output is printed, followed by a line `exit code: N`, and the command exits with that code. A probe still running after 60 seconds is stopped with exit code 124. The instance, its files and anything the probe started are removed afterwards. It needs Linux with `unshare` and `ip`, which cut the network for the run.

The instance and the probe do not inherit your whole environment, so a token you have set in your shell is not visible to them. They get only `PATH`, `HOME`, `LANG`, `LC_ALL`, `LC_CTYPE`, `TZ` and `TMPDIR` (when you set them), the application variables `bin/instance` sets itself (`APP_*` and `DATABASE_URL`) and `INTAKT_INSTANCE_URL`. `PWD` is also present: the `sh -c` wrapper in `bin/instance` sets it, it is not taken from your environment. `APP_ENV` and `APP_SECRET` keep your value when it is set and not empty or `0`.

A caller that already runs `bin/probe` without network access and in its own process namespace (as the build loop's sandbox does) sets the environment variable `PROBE_ISOLATED=1`. `bin/probe` then starts the instance directly and needs neither `unshare` nor `ip`, but first checks that the run really is offline: when `/proc/net/dev` cannot be read or lists something unexpected, when it shows a network interface other than `lo`, or when `lo` is down, it prints the reason and exits with code 2 before anything starts. The caller is responsible for ending whatever the probe leaves running. Any other value, or no value, keeps the `unshare` way.

## Configuring providers and API keys

Intakt knows four providers: `anthropic`, `openai`, `openai_compatible` (any endpoint that speaks the OpenAI API) and `claude_code` (a reviewer on a Claude subscription, see below). Each reads its key from Symfony secrets (`php bin/console secrets:set ANTHROPIC_API_KEY`) or from the environment (`.env.local` or the real environment), never from a plain committed file; a non-empty environment value wins over the secret:

| Provider | Variables it needs |
|---|---|
| `anthropic` | `ANTHROPIC_API_KEY` |
| `openai` | `OPENAI_API_KEY` |
| `openai_compatible` | `OPENAI_COMPATIBLE_BASE_URL` and `OPENAI_COMPATIBLE_API_KEY` |
| `claude_code` | `CLAUDE_CODE_OAUTH_TOKEN` |

A provider whose variables are not all set (empty counts as not set, and so does a secret Intakt cannot decrypt when the container is built), neither in the environment nor in the secrets, is switched off, and Intakt still starts. Each console command prints a line on standard error for every switched-off provider, for example `Provider "openai" is disabled: set OPENAI_API_KEY to enable it.` The same text is in the container parameter `intakt.ai.disabled_providers`. `php bin/console debug:config ai` lists the providers that are on, except `claude_code`, which is not part of the AI Bundle's configuration: `php bin/console debug:container intakt.ai.platform.claude_code` finds that service only when it is on. Whether a key is set is decided when the container is built, so after you add or remove a key, run `php bin/console cache:clear`.

### A reviewer on a Claude subscription

Map a tier to `provider: claude_code` and a Claude Code model name (`opus`, `sonnet` or `haiku`) to run that reviewer through the `claude` command-line tool, which must be installed where Intakt runs. Create the login token with `claude setup-token` and store it as `CLAUDE_CODE_OAUTH_TOKEN`. The tool gets only that token, your `PATH` and its own folder `var/claude-code/` (where it runs and keeps its state); every other variable of Intakt's environment reaches it empty, so neither an API key nor any other secret does, and the subscription is used. It is given no tools and does not see the checkout or Intakt's files, so for now this reviewer judges the change from the task text alone; reading the checkout comes with a later part (Intakt's own reading tools served to it). Because it cannot read the change, it cannot approve it: when it answers `approve`, its verdict becomes `blocked` with the reason that it approved a change it could not read. A `changes` verdict from it stays `changes`. Intakt reads the environment again for every call, so a variable added while Intakt runs also reaches the tool empty. Its usage is recorded in the ledger like any other call. If its output ends before the final result (the tool stopped, was killed or ran past its 300-second limit), the reviewer's verdict is `blocked` with that reason, and its usage is recorded once as unsettled.

Which plans may be used this way is the vendor's decision, not Intakt's. Anthropic's [Claude Code legal and compliance page](https://code.claude.com/docs/en/legal-and-compliance) says that Free, Pro and Max plans fall under the Consumer Terms of Service and that Team and Enterprise plans fall under the Commercial Terms of Service, that the subscription login is meant for ordinary use of Claude Code by the plan's own purchaser, and that routing requests through Free, Pro or Max credentials on behalf of other users is not permitted. Use your own plan for your own reviews, read those terms for your case, and use an API key (`anthropic`) when in doubt. Intakt does not check your plan.

To decrypt the secrets, Intakt reads `SYMFONY_DECRYPTION_SECRET` the way Symfony does: a base64 value, in the standard or the URL-safe alphabet (`-` and `_`). When it is not set, or decodes to nothing, the vault's own private key file in `config/secrets/<environment>/` is used. A secret that cannot be decrypted (a wrong key, or no key at all) counts as not set, so its provider is switched off and named on standard error as above.

### Capability tiers per project

Each project maps three capability tiers (`strong`, `standard`, `light`) to a provider and a model. Copy `config/intakt.example.yaml` to `config/intakt.yaml` (or point `INTAKT_CONFIG` at another file), edit it, and run `php bin/console cache:clear`. Project names are kept exactly as written, dashes included, and a model must be a text value that is valid UTF-8 and not blank (a value made only of whitespace, including Unicode spaces such as a no-break space, counts as blank). Several projects can map the tiers differently; a tier you leave out is reported as not mapped. A model name the provider's built-in list does not know is added to it, so newer models work too. An invalid file stops Intakt from starting and names the bad key. `php bin/console intakt:ai:tiers` lists every project with the provider and model of each tier; a tier whose provider is switched off (see above) shows the reason instead of a model.

A project can also carry a `review:` section that sets its review panel (see `config/intakt.example.yaml`): `quorum` is `all`, `majority` or `one-strong-plus-one`, and `reviewers` lists one or more reviewers, each with a `role`, a `tier` (`strong`, `standard`, `light`), a `provider` (`anthropic`, `openai`, `openai_compatible`), a `prompt` and a `budget` (a whole number of at least 1). Every field is required, and the panel comes from the file alone. A reviewer's `provider` must be the provider the project maps that `tier` to under `tiers:`. A panel of one, two or three reviewers works, as long as its policy can be met when every reviewer approves: `one-strong-plus-one` needs at least two reviewers, one of them on the `strong` tier. An unknown policy, tier or provider, a missing, empty or blank field, a budget below 1, an empty reviewer list, reviewers written as a map instead of a list, a provider that differs from the tier's, or a policy the panel can never meet stops Intakt from starting and names the problem. A `projects:` value that is not a map of projects stops Intakt too. A project without a `review:` section has no panel yet.

A `review:` section can also hold `rules`, a list of extra reviewers for bigger or sensitive changes. Each rule has `min_lines` (a whole number of at least 1), `paths` (a list of path patterns) or both, and `reviewers` written like the core ones (the provider again must be the tier's). A rule without `min_lines` and `paths`, rules or paths written as a map instead of a list, or a path pattern that is not text (a number, `true`) or not valid UTF-8 stops Intakt from starting. The quorum policy must be reachable both by the core panel alone and with every rule added, otherwise Intakt does not start either. A rule applies when the change has at least `min_lines` changed lines or touches a path matching one of its patterns; in a pattern `*` stays inside one folder (`src/*` does not match `src/a/b.php`) and `**` crosses folders (`src/**`), any characters included; `/**/` also matches no folder (`src/**/Security/**` matches `src/Security/Door.php`), and a leading `**/` matches the top folder too (`**/Security/**` matches `Security/Door.php`); quote a pattern that starts with `*` (`'**/Security/**'`), since YAML reads a bare leading `*` as a reference. The reviewers of every matching rule join the core panel, which is never reduced, and the project's quorum policy stays. The label `review:full` applies every rule. The panel comes from the configuration alone.

To see which panel a change gets, without calling any model, run `php bin/console intakt:review:panel <project> --lines=300 --path=src/Foo.php --label=review:full` (`--path` and `--label` can be repeated; all options are optional, and `--lines` must be zero or more). It prints the quorum and one line per reviewer with its tier, provider and budget. Intakt reads the configuration when it starts, so a changed file shows after the cache is cleared. An unknown project fails with a message. Nothing runs the panel yet, so the labels are typed in.

## Looking inside the development install with Mate

On a development install (`composer install` with the dev packages), `vendor/bin/mate` answers questions about the running app from its own container and environment files instead of from reading the code. It is not part of a production install: `composer install --no-dev` leaves it out.

- `vendor/bin/mate tools:list` lists the tools.
- `vendor/bin/mate tools:inspect TOOL` shows a tool's parameters.
- `vendor/bin/mate tools:call TOOL --PARAM=VALUE` runs one; add `--format=json` for machine-readable output.

The useful tools today are `symfony-services` and `symfony-service-detail` (which services the container holds and how one is wired), `symfony-dotenv-check` (which `.env*` file declares a variable and whether it resolves, with the value masked) and `server-info` (PHP version, system and extensions). The profiler tools are listed too, but Intakt does not turn on Symfony's profiler, so they find no profiles.

If Mate stops with a PHP version mismatch, it was started with a PHP other than 8.5 (the version `mate/config.php` pins); run it with the PHP 8.5 the app uses. If a container tool reports "No compiled container found", run `bin/console cache:warmup` first so the dev container exists. If `tools:list` says "No tools found", check whether `vendor/` is a symlink: Mate then looks for its settings next to the folder the symlink points to and finds none, so it needs a real `vendor/` folder in the project.

## The ledger

Intakt has a ledger that can store review events (review started, reviewer started, verdict received, judge done, usage recorded) in one SQLite table, `ledger_event`, and read them back in the order they were stored, even if the system clock is set back in between. Each event gets an id that carries the time it was stored (a UUID version 7) and keeps the moment it was stored in UTC, and reads it back the same whatever time zone PHP is set to. Only the model calls of the vendor write to it so far (their token usage). Create the table with:

```
php bin/console doctrine:migrations:migrate
```

### Choosing the database

Intakt uses SQLite unless you set `DATABASE_URL` (in `.env.local` or the real environment) to another database; MySQL, MariaDB and PostgreSQL are tested too, for example `DATABASE_URL="postgresql://intakt@localhost:5432/intakt"`. Intakt finds out the MySQL or MariaDB server version itself on the first connection. For PostgreSQL it does not ask the server and always uses its support for PostgreSQL 12 and later, the newest it has. The test environment appends `_test` to the database name, so tests against `intakt` use the database `intakt_test`. In CI, a pull request runs the tests on MySQL, MariaDB and PostgreSQL only when it touches persistence (the ledger, migrations, Doctrine's configuration, the Composer files or the CI definition under `.github/workflows/` and `.github/ci/`, or adds a line naming Doctrine in `src/`); when that check fails, the CI run fails rather than skipping them, and every push to main runs them. SQLite runs on every change.

Stored events cannot be changed or removed. Intakt refuses to update or delete one with a `LedgerEventsAreAppendOnly` error, and on SQLite the same migration adds database triggers that abort any `UPDATE` or `DELETE` on the table, and any insert that would replace a stored event (for example `INSERT OR REPLACE` with the position or id of an existing event), even from another tool. On other database engines only the refusal inside Intakt applies.

Read the events back, oldest first, with:

```
php bin/console intakt:ledger:events
```

It prints one line per event (the moment in UTC, the id, the kind and the payload) and ends with a count, for example `2 events`. The payload is printed exactly as stored, console color tags included. The ledger has no notion of a run yet, so the command lists every stored event.

## Summarizing a branch

`php bin/console intakt:change:summarize <branch> [--repository=.] [--base=main]` prints the change summary of a branch of a local repository against its base branch: the short base and head commits, the number of changed files with the added and removed lines, and one line per file (`added`, `modified` or `deleted`). A control character in a file name is shown in a visible form such as `\x1b`, and a backslash as `\\`. A branch the repository does not have, a repository git cannot read, or a repository whose `.git/info/attributes` file exists, prints an error and exits with 1. The summary is what the branch has gained since it was cut from the base branch, not what the base branch gained afterwards.

## Checking a new Symfony AI release against the known bugs

`php bin/console intakt:upstream:run` runs every reproduction in `upstream/` and prints the installed Symfony AI versions, then `passes` (the bug seems fixed) or `still fails` for each reproduction, and a count. Add `-v` to see why a reproduction fails. The exit code is 1 while any reproduction still fails. A reproduction counts as `passes` only when its PHPUnit tests ran and asserted something, or when its `reproduce.php` script exits with 0 and prints `REPRODUCTION PASSES` as its last line. One without tests or script, with a skipped or incomplete test, or that runs longer than 5 minutes is reported as `still fails`, and the other reproductions still run. A folder that does not exist or cannot be read is an error (exit code 2).

## Asking a model for a verdict

Inside Intakt, the vendor asks the model of a project's capability tier to review a change and gets back a verdict: an approve, changes or blocked decision with findings. The model is a reviewer agent: it gets a role prompt, the task and the rules of how code reads, and it may read the files of the head under review with the tools described below before it answers. Each finding names the rule it breaks. The task text and everything the tools return are marked as untrusted data in the prompt, so text in a task or in the code under review cannot give the reviewer orders. A fence ends only at a closing marker that repeats its own boundary, so a fake closing marker in a file stays data. A reviewer that keeps asking for tools without answering (30 rounds of requests, however many files one round asks for), that makes a malformed tool request (a missing argument, a path that is not text, or a tool that does not exist), or that answers with something that is not a verdict counts as changes. There is no command for it yet; the review loop will use it.

Each reviewer keeps its conversation of a review round (the prompt, the model's answers, the tool calls and what they returned) in the database table `reviewer_conversation`, one conversation per reviewer and round, so a reviewer never sees what another one was told or answered; a review that would reuse a conversation already started is refused before the model is asked. `php bin/console doctrine:migrations:migrate` creates it with the ledger table. Prompt caching is on for Anthropic models (the five-minute window), so a round that follows another in a review pays less for the part of the conversation the provider already holds.

Every such review is recorded in the ledger as `usage_recorded` events: one when it starts, and one when it ends, either with the tokens it used in all its rounds (one entry per model call, because the reviewer may call tools between answers) (prompt, completion, cached and the others the provider reports, plus the remaining-token figures) or marked unsettled when the call failed, with the tokens of the rounds that had answered before it failed. Both carry the same call id, so a call is counted once. An answer that is not a valid verdict still records the tokens it used. If the tokens of an answer cannot be read, the call is marked unsettled and a warning is logged, but the review still gets its verdict. If the ledger cannot store the first row, the call is not sent; if it cannot store the last one, the call fails and the row, tokens included, is logged as an error so it can be recorded later. `php bin/console intakt:ledger:events` shows the rows.

A call the provider refuses because of a rate limit or quota fails with a clear error that carries the time to wait when the provider sent one; other temporary provider errors fail with their own error, so a later retry can tell them apart.

Run `php bin/console intakt:vendor:probe` after you set or change a provider key, to check the setup before a first review. It checks the real setup: it sends one tiny call to each enabled provider your projects use and prints a line with the model and the tokens used (prompt, completion, cached, total, remaining). It uses the keys from your environment, costs a few tokens per provider, and exits with 1 if any call fails. A failed call shows only the kind of error, never the provider's message, so a key it echoes stays off your screen. A provider that is switched off (see the `Provider "..." is disabled` lines above) is not probed.

## What a reviewer can read

A reviewer reads the change through three read-only tools: it can read a file, list a directory and search for text, all at the head under review and nothing else. A request for a file outside the repository (a `../` path, an absolute path, the `.git` folder, or a link pointing out of the checkout or into the `.git` folder) is refused with a message, and the file is never opened. A tool that fails, such as a file that does not exist or a binary file, gives the reviewer an error message and the review goes on. A search skips binary files and files over 1 MB, shortens very long matching lines and returns at most 100 matches; a listing of a very large directory is shortened too. The reviewer agent uses these tools; a request it makes that is refused or fails is answered with a message and the review goes on.
