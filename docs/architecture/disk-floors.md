# Disk floors

## In short

Before Intakt makes a build clone or a review snapshot, it reads the free bytes and free inodes of the folder the checkout lands in and compares them with two floors. Below either floor it makes nothing and fails the step with `NewWorkHeld`, naming the floor and the folder. Nothing is stored: the next try reads the disk again, so the hold clears on its own. Work already running is never cut, because it never asks again. The floors are checked when the container is built.

## The parts

- `src/Core/Disk/Contract/`: `DiskHeadroom::held()` returns a `DiskHeld` (the `DiskFloor` crossed, `Bytes` or `Inodes`, the folder, the free amount and the floor) or `null` for the watched folder; `DiskHeadroom::admitWorkAt(destination)` throws `NewWorkHeld` (a `ReportableFailure` carrying the `DiskHeld`) when the destination's file system is below a floor; `FreeSpaceReader` is the boundary tests replace; `DiskServices` names the parameters.
- `FloorCheck` (Domain) reads bytes first, then inodes, and names the first floor crossed. A floor of exactly the free amount does not hold. For a destination that does not exist yet it reads the deepest parent folder that exists, because a checkout can land on another file system than `var/`.
- `StatFreeSpace` (Infrastructure) reads bytes with `disk_free_space()` and inodes with `stat --file-system --format='%d %c'` through Symfony Process ([requirements](requirements.md)). A total inode count of 0 means the file system keeps no count (Btrfs, for one), so its free inodes are unlimited. A folder or tool that cannot be read throws `FreeSpaceUnreadable`, so the step fails visibly instead of passing a disk it could not measure.
- `DiskFloorsPass` (Infrastructure, registered in `Kernel::addApplicationPasses()`) refuses to build the container unless both floors are whole numbers of at least 1 (`FloorRefused`), so a bad floor stops `bin/console` and the server from booting. `FloorCheck` applies the same rule when it is built by hand.

## Where it holds

- `Core/Git` `MakeBuildClone` calls `admitWorkAt()` with the clone's folder before it reserves it, so `intakt:build:clone` and the build's `GitBuildCheckout::makeClone()` (which turns the failure into `BuildCheckoutFailed`) make no folder below a floor.
- `Plugin/LocalGitForge` `LocalChangeReview` calls it with `LocalGitForge::snapshotFolder()` (the system temporary folder) before `withSnapshotOf()` and `keepSnapshotOf()`, so `intakt:review`, `intakt:demo`, the `start_review` tool and the start handlers make no worktree below a floor. A watched head whose start fails this way backs off and is tried again like any failed start.
- `BudgetGuard` asks `held()` after the watchdog halt (only the owner lifts a halt) and before the shared holds, for `decide()` and `decideMerge()`, answering `disk-low`. No step or merge consults `StartGuard` yet (see [Measured use](measured-use.md)). A replayed day uses `ReplayedDisk`, which never holds.

The owner's evidence boot (`InstanceBoot`) takes no hold, because it is not new work of the loop.

## Settings

`config/services.yaml` holds `intakt.disk.watched_directory` (`var/`, where the mirrors, job folders, clones and database live; read by `held()`), `intakt.disk.free_bytes_floor` (1 GiB) and `intakt.disk.free_inodes_floor` (10,000). See [When the disk runs low](../reference/user-manual.md#when-the-disk-runs-low).

## Not here

Showing the held state on the Status page, and pruning old per-head checkouts.
