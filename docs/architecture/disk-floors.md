# Disk floors

Callers can ask whether a folder's disk is close to full and hold new work there, so a checkout or a state write does not fail half-way on a full disk. The module is `src/Core/Disk/`.

## The check

`DiskSpace::holdAt($folder)` (`src/Core/Disk/Contract/DiskSpace.php`, implemented by `src/Core/Disk/Infrastructure/FlooredDiskSpace.php`) reads the free bytes of the folder's file system, then its free inodes, and compares each with its floor:

- Free bytes below `disk.min_free_bytes`: it returns a `DiskHold` with the floor `DiskFloor::Bytes`. The inode count is not read then, so a disk below both floors names bytes.
- Otherwise, free inodes below `disk.min_free_inodes`: it returns a `DiskHold` with the floor `DiskFloor::Inodes`.
- Otherwise it returns `null`: the folder is not held. A reading equal to its floor is not held.

`DiskHold` (`src/Core/Disk/Contract/DiskHold.php`) is the typed value a caller or a page reads: `floor` (which floor was crossed), `folder` (where), `free` (the reading) and `minimum` (the floor). `DiskHold::reason()` words it for a failure message: `the disk of <folder> holds new work: <free> free bytes, below the floor of <minimum>` (or `inodes`).

The hold is measured on every call and never stored, so it clears by itself on the first call after space is back. It only answers whether new work may start; it does not stop work that is already running. The Status page shows it (see [The Status page](#the-status-page)).

## The callers

Two Git use cases ask the check before they write, each on the folder it writes into. Each measures before it makes any folder, because on a disk out of inodes `mkdir` itself fails; a folder that does not exist yet is measured at the nearest folder above it that does (`NearestFolder::of()`, `src/Core/Disk/Contract/NearestFolder.php`):

- `UpdateMirror::update()` (`src/Core/Git/UseCase/UpdateMirror/UpdateMirror.php`), on the folder it writes to, before it clones or fetches anything: the project's mirror (`var/mirrors/<project>.git`) when a valid mirror is there, since the fetch fills it and it may be mounted on a disk of its own, otherwise the mirrors folder or the nearest folder above it, where the clone is staged. A folder at the mirror's path that is no mirror is replaced through that staging folder, so it is measured at the mirrors folder too. The write follows the answer it was measured for: when a mirror appears or disappears at that path during the reading, the update fails with `MirrorNotUpdated` ("the mirror changed while its disk was measured") instead of writing to a disk it did not measure, and the next update measures again. A hold throws `MirrorNotUpdated` with `DiskHold::reason()` and a `NewWorkHeld` as its previous exception. See [Mirrors](mirror.md).
- `MakeBuildClone::make()` (`src/Core/Git/UseCase/MakeBuildClone/MakeBuildClone.php`), on the parent folder of the clone (or the nearest folder above it), after the mirror and branch checks and before it reserves the clone's folder. A hold throws `BuildCloneNotMade` with `DiskHold::reason()` and a `NewWorkHeld` as its previous exception, so no folder is made. See [Build clones](build-clone.md).

In both, a `FreeSpaceUnreadable` is caught and thrown again as the same failure, with its message as the reason and itself as the previous exception. An inode read that times out is caught there too and thrown as the same failure with fixed words, `The free space of <folder> cannot be read: df timed out after 10 seconds.`, without the timeout as its cause (its message holds the command line). Both failures are `ReportableFailure`s: `intakt:mirror:update` and `intakt:build:clone` print them and exit with 1, and `GitBuildCheckout` turns them into `BuildCheckoutFailed`, so `intakt:build:task` records a failed `mirror` or `clone` step ([Build task](build-task.md)); `BuildCheckoutFailed` keeps the step's failure as its previous exception, so the `NewWorkHeld` of a hold stays in the chain.

## The Status page

`HeldForSpace::now()` (`src/Core/Dashboard/UseCase/ShowLanes/HeldForSpace.php`) asks `DiskSpace::holdAt()` on every load of `/lanes` for `NearestFolder::of()` of the mirrors folder (`GitServices::MIRRORS_DIRECTORY`) first. When that folder is held, it shows the hold at once and does not list the mirrors. Otherwise (not held, or unreadable) it asks the same for each mirror in it, since a mirror can sit on a disk of its own and `UpdateMirror` measures that one. The mirrors come from the Git module's `Contract/MirrorFolders::existing()`, which returns them as a `Contract/MirrorPaths` (an iterable value of paths), implemented by `UseCase/UpdateMirror/MirrorsOnDisk`: it reads the mirrors folder entry by entry (no glob pattern, so brackets in the install's path are literal), keeps each `<project>.git` whose name `Mirrors::pathOf()` accepts and that `UpdateMirror::isMirror()` accepts, the same rule the update decides its folder by, and leaves out an entry whose check throws. Any other folder there (a backup, `lost+found`, a staging folder, a folder that is no bare repository) is not measured, because no mirror update writes into it. The page stops at the first hold; a reading that fails does not stop it, and its broken line is shown only when no folder is held. A build clone's folder comes from the caller (`intakt:build:task --directory`), so there is no folder to measure for it. The line says that a step refused meanwhile must be run again, because a held build command records a failed step. It keeps nothing, so the line is gone on the first load after space is back. A `DiskHold` becomes plain words written in the dashboard, not `DiskHold::reason()`: the floor (free space, or free file slots for inodes), the folder, the reading and the floor, bytes in binary units with one decimal rounded down (when the two still read equal, exact bytes, `1 byte` in the singular), inodes as grouped whole numbers, and the setting's key. A `FreeSpaceUnreadable`, or a `ProcessTimedOutException` worded as `df timed out after <n> seconds`, becomes a broken line with its message, so the page still renders. See [Dashboard](dashboard.md).

## A hold is a wait

`NewWorkHeld` (`src/Core/Disk/Contract/NewWorkHeld.php`) carries the `DiskHold` (`hold`) and says `DiskHold::reason()` as its message. It lets a caller further up tell a hold, which clears by itself, from a failure by its type. The watcher does: when a watched head's step fails with a `NewWorkHeld` anywhere in its cause chain, `FailedHeads::backOff()` (`src/Core/Watcher/UseCase/WatchPullRequests/FailedHeads.php`) logs one info line (`A head waits for room on the disk.`, with `project`, `change`, `head`) and moves the head's read-again time 60 seconds on through `PendingHeads::waitUntil()`. It counts no failure (the head's failures in a row stay as they were, so a long low-disk spell hands no head over), appends no `head_failed` or `head_handed_over` line and reports nothing to `InstallWideFailures`, so two held heads with the same words start no failure back-off. A `FreeSpaceUnreadable` or a `df` timeout is no hold and stays an ordinary failure of the head. `HeadBackOff` (`src/Core/Watcher/UseCase/WatchPullRequests/HeadBackOff.php`) asks `FailedHeads::isHeldByTheDisk()` first, so a hold is never logged as `A head failed`; if the wait cannot be stored it logs an error and the head stays due, and the heads after it still run. No watched head's step asks the disk check yet: the watcher's round start reaches neither `UpdateMirror::update()` nor `MakeBuildClone::make()`, so today only `FailedHeadsTest` and the wired test below send a hold this way.

Work already running is not held: the pre-push check of a running build brings the mirror up to date through `UpdateMirror::refresh()` (`BuildCheckout::refreshMirror()`), which does the same update without the check.

## The readings

`FreeSpaceReader` (`src/Core/Disk/Infrastructure/FreeSpaceReader.php`) is the small interface the check reads through, so tests set the readings. The production reader is `SystemFreeSpaceReader`:

- Free bytes come from PHP's `disk_free_space()`. A folder that does not exist, or whose file system cannot be read, raises `FreeSpaceUnreadable`.
- Free inodes come from `df --output=itotal,iavail -- <folder>` (GNU coreutils), run through Symfony Process with `LC_ALL=C` and a 10 second timeout. A non-zero exit (a missing folder, a missing `df`), a `df` stopped by a signal, or output that is not one header line and one line of two whole numbers raises `FreeSpaceUnreadable`. A file system that reports a total of 0 inodes (btrfs allocates them as it goes) has no inode floor, and only its bytes are checked.
- An inode read that times out is not caught: the check propagates Symfony Process's `ProcessTimedOutException`, so a caller sees a hung mount as the timeout it is.

`FreeSpaceUnreadable` (`src/Core/Disk/Contract/FreeSpaceUnreadable.php`) names the folder and the reason. The check never treats an unreadable disk as having room.

## Settings

Both floors are in the `disk` section of `config/intakt.yaml` (`src/Core/Disk/Infrastructure/DiskConfiguration.php`), read in `config/packages/ai.php` into the parameters `DiskServices::MIN_FREE_BYTES` and `DiskServices::MIN_FREE_INODES`:

| Key | Default | Allowed |
|---|---|---|
| `disk.min_free_bytes` | 2147483648 (2 GiB) | a whole number of at least 1 |
| `disk.min_free_inodes` | 100000 | a whole number of at least 1 |

A value that is not a whole number of at least 1, or an unknown key under `disk`, stops the application at boot with a message naming the key.

## Tests

- `tests/Core/Disk/Infrastructure/FlooredDiskSpaceTest.php`: held below each floor, not held at it, clearing when the readings rise, `FreeSpaceUnreadable` and the process timeout passing through.
- `tests/Core/Disk/Infrastructure/SystemFreeSpaceReaderTest.php`: real readings of an existing folder, a missing folder, a `df` stopped by a signal, and the parsing of `df`'s output.
- `tests/Core/Disk/Infrastructure/DiskConfigurationTest.php`: defaults, boundaries and refused values.
- `tests/Core/Disk/DiskSpaceOnTheContainerTest.php`: the wired container, with `tests/Core/Disk/ControlledFreeSpaceReader.php` in place of the disk and a scratch folder for the mirrors and clones, holding the check, the mirror update and the build clone at each floor and resuming above it, and the wired `FailedHeads` counting no failure for a head whose mirror update was held at each floor.
- `tests/Core/Disk/Contract/NewWorkHeldTest.php`: the hold it carries and its message.
- `tests/Core/Watcher/UseCase/WatchPullRequests/FailedHeadsTest.php`: a head held by the disk (the hold two causes deep) waiting a minute with no failure counted and no card, a held head with three failures in a row not handed over, two held heads starting no failure back-off, and an unreadable disk still counted as a failure.
- `tests/Core/Git/UseCase/UpdateMirror/UpdateMirrorBelowTheDiskFloorsTest.php` and `tests/Core/Git/UseCase/MakeBuildClone/MakeBuildCloneBelowTheDiskFloorsTest.php`: each use case held below each floor without making a folder, a mirror, a fetch or a clone, with the `NewWorkHeld` as the failure's cause, measured at the nearest folder that exists, going ahead at the floors, and failing on a reader that throws or an inode read that times out (naming `df`); an existing mirror held when only its own disk is below a floor, a folder that is no mirror held when only the mirrors folder is below a floor and replaced when only that folder reads below it, and `UpdateMirror::refresh()` going ahead below the floors.
- `tests/Core/Dashboard/UseCase/ShowLanes/HeldForSpaceTest.php` and `tests/Core/Dashboard/UseCase/ShowLanesControllerTest.php`: the plain words for each floor, the rounding, no line above the floors, the line gone on the next read, the nearest folder measured, an unreadable disk and a `df` timeout as a broken line, a held mirror on a disk of its own (a real bare mirror, also under a path with brackets), no line for a folder that is no mirror, a hold shown before an unreadable reading, a hold on the mirrors folder shown without listing the mirrors, the mirrors listed when that folder is not held or cannot be read, and the wired page with and without the banner.
- `tests/Core/Git/UseCase/UpdateMirror/MirrorsOnDiskTest.php`: the mirrors returned as one `MirrorPaths`, a bare mirror listed by its path, and nothing listed for an empty folder, a bare repository under a name no update writes to, a repository with a work tree, a file, a mirror whose check is refused, a missing mirrors folder; brackets in the path read literally.
- `tests/Core/Disk/Contract/NearestFolderTest.php`: the nearest folder that exists, past missing folders and a file on the path.
- `tests/Core/Build/UseCase/BuildTask/BuildTaskTest.php`: a reader that throws at the mirrors folder or at the clone's parent ends the run at the `mirror` or the `clone` step with the failure recorded, and a disk that falls below a floor after the build job still reaches the check job.
