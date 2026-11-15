## Symfony components

| Filesystem | the demo's live-update capture appends each update to one file (`appendToFile()`), and `intakt:demo:record` reads it (`readFile()`) and writes the recording through a temporary file renamed into place (`dumpFile()`) | in progress | #2056 (part of #1944), `src/Core/Dashboard/Infrastructure/CapturingLiveUpdates.php`, `src/Core/Dashboard/UseCase/RecordDemo/RecordDemoCommand.php` |
| Lock | one live update at a time from its publish to its capture line while the demo's live-update capture runs, waited for, so the capture keeps the order the hub received | in progress | #2056 (part of #1944), `src/Core/Dashboard/Infrastructure/CapturingLiveUpdates.php` |
