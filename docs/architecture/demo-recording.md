## The demo recording

Issue #2056 (part 1 of #1944). A recording is one file that holds the ledger events and the live dashboard updates of one real review of a change, with the moment the review started, the models it called and the Intakt version that wrote it. Replaying it into a demo project and the `/demo` page are later parts and not built.

### Capturing the live updates

The ledger holds no live updates, so they are captured while the review runs. `Core/Dashboard/Infrastructure/CapturingLiveUpdates` decorates `Core/Dashboard/Contract/LiveUpdates` (`#[AsDecorator]`; `MercureLiveUpdates` is the alias it decorates, `#[AsAlias]`). Every update goes to the hub first, as before. When `INTAKT_LIVE_CAPTURE` (`.env` sets it empty) names a file, the update is then appended to it as one JSON line (`at`, `topic`, `data`: the moment from the clock, the topic and the Turbo stream) with Symfony Filesystem's `appendToFile()` under a lock, because every web and queue worker appends to the one file. A capture that cannot be written logs the warning `A live dashboard update was not captured.` and the work that published goes on, as for a hub that is down. With the variable empty nothing is written.

### Writing the recording

`Core/Dashboard/UseCase/RecordDemo/RecordDemoCommand` (`intakt:demo:record <project> <change> <recording>`):

1. `ReviewRowsOfChange` reads the ledger in order. The rounds are the `review_started` rows (read through the dashboard's `RoundPayloads::startOf()`) of that project and change. A row belongs to the review when its payload's `round` is one of them, or its `call` is a model call whose `usage_recorded` row of phase `started` names one of them (a settled usage names only its call). The models are the distinct non-empty `model` values of those started calls, in the order first called.
2. With no round, it prints `No review round of change <change> in project <project> is in the ledger.` and exits 1.
3. `CapturedUpdates` reads the capture file `INTAKT_LIVE_CAPTURE` names and keeps the updates whose topic is a round's topic (`MercureRoundUpdates::topicOf()`, `review/rounds/<round>`), in capture order. It exits 1 without writing when the variable is empty (`Set INTAKT_LIVE_CAPTURE to the file the live updates were captured in.`), the file cannot be read, a line is not a captured update (`Line <n> of <file> is not a captured live update.`, empty lines are skipped) or no update is of these rounds.
4. The recording is written with Filesystem's `dumpFile()` (a temporary file renamed into place); a file that cannot be written exits 1 with the reason. It prints `Recorded <n> ledger events and <n> live updates of <n> review rounds into <file>.`

### The format

`RecordingFormat` is the one home of both formats; it uses the `stored` serializer (snake_case keys) and writes every moment as `Y-m-d\TH:i:s.uP` in UTC, so rows and updates of one second keep their order. The recording is pretty-printed JSON with a trailing newline:

| Key | Holds |
|---|---|
| `reviewed_at` | the moment of the first round's `review_started` row |
| `intakt_version` | Composer's root package version, with `@<commit>` when Composer knows the commit (`dev-main@<sha>` in a checkout) |
| `models` | the models the rounds called |
| `project`, `change` | as given to the command |
| `events` | each row as `id`, `kind` (the `LedgerEventKind` value), `payload` (the stored text, unchanged) and `recorded_at` |
| `updates` | each captured update as `at`, `topic` and `data` |

`RecordingFormat::read()` turns a file that is not a recording (not JSON, a missing key, an unknown event kind, a model that is not text, a moment in another form) into `RecordingIsMalformed`; a recording read back writes the same text again.

Tests: `tests/Core/Dashboard/UseCase/RecordDemo/RecordDemoCommandTest.php` (the rows of both rounds in ledger order and not another change's, each row's id, payload and moment, the date, the models once each, the Intakt version, the project and change, only the rounds' updates in capture order, the summary line, and each refusal above, none writing a file), `RecordingFormatTest.php` beside it (the round trip as an equal recording and as the same text, microseconds, the payload text, a capture line, and each malformed form), `tests/Core/Dashboard/Infrastructure/CapturingLiveUpdatesTest.php` (the update still published, one line per update, nothing without the variable, a failed write logged) and `CapturingLiveUpdatesWiringTest.php` beside it (the container's `LiveUpdates` is the capture around the Mercure hub).
