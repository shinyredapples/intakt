## Symfony components

| Filesystem | the demo's live-update capture appends each update to one file under a lock (`appendToFile()`), and `intakt:demo:record` reads it (`readFile()`) and writes the recording through a temporary file renamed into place (`dumpFile()`) | in progress | #2056 (part of #1944), `src/Core/Dashboard/Infrastructure/CapturingLiveUpdates.php`, `src/Core/Dashboard/UseCase/RecordDemo/RecordDemoCommand.php` |
