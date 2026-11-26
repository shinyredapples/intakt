# Disk floors

## In short

Before Intakt updates a mirror, makes a build clone or a review snapshot, it reads the free bytes and free inodes of each folder it would write to and compares them with two floors. Below either floor it makes nothing and fails the step with `NewWorkHeld`, naming the floor and the folder. Nothing is stored: the next try reads the disk again, so the hold clears on its own. Work already running is never cut: a build that already started refreshes its mirror before the pre-push check without a hold. The floors are checked when the container is built.

## The parts

- `src/Core/Disk/Contract/`: `DiskHeadroom::held()` returns a `DiskHeld` (the `DiskFloor` crossed, `Bytes` or `Inodes`, the folder, the free amount and the floor) or `null` for the watched folder; `DiskHeadroom::admitWorkAt(destination)` throws `NewWorkHeld` (a `ReportableFailure` carrying the `DiskHeld`) when the destination's file system is below a floor; `FreeSpaceReader` is the boundary tests replace; `DiskServices` names the parameters.
- `FloorCheck` (Domain) reads bytes first, then inodes, and names the first floor crossed. A floor of exactly the free amount does not hold. For a destination that does not exist yet it reads the deepest parent folder that exists, because a checkout can land on another file system than `var/`.
- `StatFreeSpace` (Infrastructure) reads bytes with `disk_free_space()` and inodes with `stat --file-system --format='%d %c' -- <folder>` (the `--` so a relative folder that starts with a dash is a folder, never an option) through Symfony Process ([requirements](requirements.md)). A total inode count of 0 means the file system keeps no count (Btrfs, for one), so its free inodes are unlimited. A folder or tool that cannot be read throws `FreeSpaceUnreadable` (a `ReportableFailure` in the Contract), so the step fails visibly instead of passing a disk it could not measure: a console command prints its message, and `GitBuildCheckout` turns it into `BuildCheckoutFailed`, so a build's mirror, clone or pre-push refresh step records it as a failed step.
- `DiskFloorsPass` (Infrastructure, registered in `Kernel::addApplicationPasses()`) refuses to build the container unless both floors are whole numbers of at least 1 (`FloorRefused`), so a bad floor stops `bin/console` and the server from booting. `FloorCheck` applies the same rule when it is built by hand.

## Where it holds

- `Core/Git` `UpdateMirror::update()` calls `admitWorkAt()` with the mirror's path before it clones a new mirror or fetches into an existing one, so `intakt:mirror:update` and the build's mirror step (`GitBuildCheckout::updateMirror()`, which turns the failure into `BuildCheckoutFailed`) write nothing into the mirrors folder below a floor. `BuildTask`'s pre-push check calls the same update; when the disk holds it (`BuildCheckoutFailed` whose cause is `NewWorkHeld`) the check goes on with the mirror the mirror step fetched, because that run already started.
- `Core/Git` `MakeBuildClone` calls `admitWorkAt()` with the clone's folder before it reserves it, so `intakt:build:clone` and the build's `GitBuildCheckout::makeClone()` (which turns the failure into `BuildCheckoutFailed`) make no folder below a floor.
- `Plugin/LocalGitForge` `LocalChangeReview` calls it with each of `LocalGitForge::snapshotFolders()` before `withSnapshotOf()` and `keepSnapshotOf()`: the system temporary folder, where the snapshot goes, and the repository's common git folder (`git rev-parse --path-format=absolute --git-common-dir`), where `worktree add` writes the snapshot's metadata under `worktrees/`. So `intakt:review`, `intakt:demo`, the `start_review` tool and the start handlers make no worktree below a floor on either disk. A watched head whose start fails this way (`NewWorkHeld` anywhere in the failure's chain, since the command bus wraps it) is no failure of that head: `Core/Watcher` `HeadBackOff` logs `A head waits for room on the disk.` with the floor and the folder, and stores a wait of 60 seconds through `PendingHeads::waitUntil()`, which keeps the head's failure count, so a low disk never hands a head over and records no install-wide failure. The head is tried again after the wait, and its round starts once space is back.
- `BudgetGuard` asks `held()` after the watchdog halt (only the owner lifts a halt) and before the shared holds, for `decide()` and `decideMerge()`, answering `disk-low`. No step or merge consults `StartGuard` yet (see [Measured use](measured-use.md)). A replayed day uses `ReplayedDisk`, which never holds.

The owner's evidence boot (`InstanceBoot`) takes no hold, because it is not new work of the loop.

## Settings

`config/services.yaml` holds `intakt.disk.watched_directory` (`var/`, where the mirrors, job folders, clones and database live; read by `held()`), `intakt.disk.free_bytes_floor` (1 GiB) and `intakt.disk.free_inodes_floor` (10,000). See [When the disk runs low](../reference/user-manual.md#when-the-disk-runs-low).

## Not here

Showing the held state on the Status page, and pruning old per-head checkouts.
