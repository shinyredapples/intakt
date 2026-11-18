## Symfony components

| Lock (shared lock) | one lock per dependency snapshot of the check job's store, never waited for and without expiry: a check holds it shared (`SharedLockInterface::acquireRead()`) from its hit or save until its job ended, and exclusive (`acquire()`) while it saves or evicts the entry, so no check is handed a half-saved or removed snapshot and no snapshot is removed while a check uses it | in progress | #1203, `src/Core/HandBack/UseCase/RunCheckJob/DependencySnapshots.php` |
