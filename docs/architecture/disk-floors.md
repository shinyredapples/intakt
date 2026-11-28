# Disk floors

Callers can ask whether a folder's disk is close to full and hold new work there, so a checkout or a state write does not fail half-way on a full disk. The module is `src/Core/Disk/`.

## The check

`DiskSpace::holdAt($folder)` (`src/Core/Disk/Contract/DiskSpace.php`, implemented by `src/Core/Disk/Infrastructure/FlooredDiskSpace.php`) reads the free bytes of the folder's file system, then its free inodes, and compares each with its floor:

- Free bytes below `disk.min_free_bytes`: it returns a `DiskHold` with the floor `DiskFloor::Bytes`. The inode count is not read then, so a disk below both floors names bytes.
- Otherwise, free inodes below `disk.min_free_inodes`: it returns a `DiskHold` with the floor `DiskFloor::Inodes`.
- Otherwise it returns `null`: the folder is not held. A reading equal to its floor is not held.

`DiskHold` (`src/Core/Disk/Contract/DiskHold.php`) is the typed value a caller or a page reads: `floor` (which floor was crossed), `folder` (where), `free` (the reading) and `minimum` (the floor). `DiskHold::reason()` words it for a failure message: `the disk of <folder> holds new work: <free> free bytes, below the floor of <minimum>` (or `inodes`).

The hold is measured on every call and never stored, so it clears by itself on the first call after space is back. It only answers whether new work may start; it does not stop work that is already running. Showing the hold on the Status page is a later issue.

## The callers

Two Git use cases ask the check before they write, each on the folder it writes into. Each measures before it makes any folder, because on a disk out of inodes `mkdir` itself fails; a folder that does not exist yet is measured at the nearest folder above it that does (`NearestFolder::of()`, `src/Core/Disk/Contract/NearestFolder.php`):

- `UpdateMirror::update()` (`src/Core/Git/UseCase/UpdateMirror/UpdateMirror.php`), on the project's mirror (`var/mirrors/<project>.git`) when it exists, since it may be mounted on a disk of its own, otherwise on the mirrors folder or the nearest folder above it, before it clones or fetches anything. A hold throws `MirrorNotUpdated` with `DiskHold::reason()`. See [Mirrors](mirror.md).
- `MakeBuildClone::make()` (`src/Core/Git/UseCase/MakeBuildClone/MakeBuildClone.php`), on the parent folder of the clone (or the nearest folder above it), after the mirror and branch checks and before it reserves the clone's folder. A hold throws `BuildCloneNotMade` with `DiskHold::reason()`, so no folder is made. See [Build clones](build-clone.md).

In both, a `FreeSpaceUnreadable` is caught and thrown again as the same failure, with its message as the reason and itself as the previous exception. An inode read that times out is caught there too and thrown as the same failure with fixed words, `The free space of <folder> cannot be read: df timed out after 10 seconds.`, without the timeout as its cause (its message holds the command line). Both failures are `ReportableFailure`s: `intakt:mirror:update` and `intakt:build:clone` print them and exit with 1, and `GitBuildCheckout` turns them into `BuildCheckoutFailed`, so `intakt:build:task` records a failed `mirror` or `clone` step ([Build task](build-task.md)). A hold counts as an ordinary failure for now.

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
- `tests/Core/Disk/DiskSpaceOnTheContainerTest.php`: the wired container, with `tests/Core/Disk/ControlledFreeSpaceReader.php` in place of the disk and a scratch folder for the mirrors and clones, holding the check, the mirror update and the build clone at each floor and resuming above it.
- `tests/Core/Git/UseCase/UpdateMirror/UpdateMirrorBelowTheDiskFloorsTest.php` and `tests/Core/Git/UseCase/MakeBuildClone/MakeBuildCloneBelowTheDiskFloorsTest.php`: each use case held below each floor without making a folder, a mirror, a fetch or a clone, measured at the nearest folder that exists, going ahead at the floors, and failing on a reader that throws or an inode read that times out (naming `df`); an existing mirror held when only its own disk is below a floor, and `UpdateMirror::refresh()` going ahead below the floors.
- `tests/Core/Disk/Contract/NearestFolderTest.php`: the nearest folder that exists, past missing folders and a file on the path.
- `tests/Core/Build/UseCase/BuildTask/BuildTaskTest.php`: a reader that throws at the mirrors folder or at the clone's parent ends the run at the `mirror` or the `clone` step with the failure recorded, and a disk that falls below a floor after the build job still reaches the check job.
