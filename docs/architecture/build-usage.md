# Build usage

Plan task T49, part 3 (#1576; design: [Budget and model planner](../design/05-architecture/06-budget-and-model-planner.md), "Subscription CLIs are counted from their own logs"). A [build job](build-job.md) runs the Claude Code CLI on a subscription, which reports no usage to Intakt. After the job, Intakt reads the tokens the session spent from the job's `build.log` and records them in the build job's ledger row, for the per-task total of the [build allowance](build-allowance.md).

## What is read

The CLI runs with `--output-format stream-json --verbose`, so it writes one JSON record per line as the session runs. Each content block of a model message is one `"type":"assistant"` record carrying the message's `message.id` and `message.usage`; records that repeat an id belong to the same message. `build.log` also holds the CLI's other records (`system`, `user`, the final `result`), its errors, the build script's own lines and anything the builder wrote there; the reader skips every line that is not an `assistant` record.

`Domain/SessionLog::usageIn()` counts each message once by its id (the last record of an id gives its counts) and adds the messages up. The final `result` line is not added on top, because its usage repeats the messages'. Every `assistant` record must carry a non-empty text `message.id` and a `message.usage` with `input_tokens`, `output_tokens`, `cache_read_input_tokens` and `cache_creation_input_tokens`, each a whole number from 0 to 10^15 (`BuildUsage::MOST`), and the sum of each count must stay within that bound (`BuildUsage::plus()`); otherwise the usage is `malformed`, never zero. A log without any `assistant` record is `not_reported`. No model name is read from the log: the job's model is the one the tier chose.

The records are written as the session runs, so a session cut at its allowance or killed by the engine, which writes no `result` line, still counts the messages it logged. The log is untrusted, because the builder runs as the same user as the build script and can rewrite it, so the figures are recorded for the allowance only; no start decision or ceiling reads them.

## The separate process

`UseCase/RunBuildJob/IsolatedSessionLog::usageIn()` reads the log in a child process:

- a log over 4 MiB (`IsolatedSessionLog::MAX_BYTES`) is `too_large` and no child starts;
- the child is the PHP executable Symfony's `PhpExecutableFinder` finds, run with `-d memory_limit=64M -r`, loading only the application's autoloader (no kernel, no `.env` file) and calling `Domain/SessionLog::usageIn()` on the log, which it gets on standard input;
- every variable of the orchestrator's environment is unset for it, because that environment holds the credentials;
- it is stopped after 5 seconds (`IsolatedSessionLog::SECONDS`);
- `SessionLog` walks the log one line at a time, so a log of millions of short lines stays within the memory limit; a line over 1 MiB (`SessionLog::MAX_LINE_BYTES`) makes it `line_too_long`, and a line nested deeper than 16 levels is skipped.

The child prints one JSON line. `Domain/SessionUsage::fromAnswer()` checks it like any untrusted text: only `read` with valid counts, `not_reported`, `malformed` or `line_too_long` count; anything else, a child that fails, runs out of memory, is stopped or cannot start is `reader_failed`.

## Where it goes

`RunBuildJob` reads the usage for every job the runner ran, whatever the verdict, and puts it on `BuildJobOutcome::$usage` as a `SessionUsage`: the `UsageReading` and, when read, the `BuildUsage` counts. A job whose output the runner refused carries none (null), because no log was read.

`BuildTask` writes it into the `usage` key of the build job step's `build_step_ended` row (`EndedStep::ofBuildJob()`, also for the `asked` row `ParkedTasks::park()` writes): `reading` and the four counts, null when not read. Every other row, and the row of a job that never ran or whose end failed, holds `usage: null`. Nothing reads the key yet; the watch of a running build (#3965) will count it into the per-task total.

## The console door

`php bin/console intakt:build:usage <log>` (`UseCase/ReadBuildUsage/ReadBuildUsageCommand`) reads a kept `build.log` the same way. A link, a folder or another file that is not regular is refused without being read. The command opens the file once and reads at most 4 MiB and one byte from that handle, so a file that grows after the check never reaches memory whole; the extra byte makes it `too_large`. It prints `reading read`, one `<count name> <number>` line per count and `total <sum>`, and exits with 0; otherwise it prints `usage not read: <reading>` (or that the file could not be read) and exits with 1.

## Tests

`tests/Core/Build/Domain/SessionLogTest.php` covers the records `m1`, `m1`, `m2` counting two messages, a cut log without a `result` line, a repeated id counted once, the `result` line not added, a log with only a `result` line, each malformed count (negative, fractional, text, missing, over the bound, a list, null), a record without a usable id, a count at the bound, a sum past it, a record nested too deep, a line at and one byte over the line limit, and the reader's answer: kept readings, refused ones, and the payload. `tests/Core/Build/UseCase/RunBuildJob/IsolatedSessionLogTest.php` runs the real child: a sample log read, a log of exactly 4 MiB in two million lines read, one byte more refused without a child, a long line refused by the child, and, with a stand-in autoloader, an empty environment, the memory limit, the time limit and an invalid answer. `RunBuildJobTest` shows the usage on the outcome, `not_reported` for a killed job and none for a refused output; `BuildRunTest` the payload; `BuildTaskParksOnSqliteTest` the counts in the `asked` row on the install's database; `ReadBuildUsageCommandTest` the command on the committed sample, cut and malformed logs in `tests/Core/Build/Fixtures/build-logs/`, an oversized log, a log that grows past the limit after the check (through the stream wrapper `GrowingLog`, which counts the bytes the command reads), a link, a folder, a missing path and an unreadable file.
