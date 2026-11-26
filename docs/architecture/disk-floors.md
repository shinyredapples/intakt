# Disk floors

## In short

Before new work starts, Intakt reads the free bytes and free inodes of the folder it writes to and compares them with two floors. Below either floor the start decision is `DiskLow`. Nothing is stored: the next start reads the disk again, so the hold clears on its own. Work already running is never cut, because it never asks again.

## The parts

- `src/Core/Disk/Contract/`: `DiskHeadroom::held()` returns a `DiskHeld` (the `DiskFloor` crossed, `Bytes` or `Inodes`, the folder, the free amount and the floor) or `null`; `FreeSpaceReader` is the boundary tests replace; `DiskServices` names the parameters.
- `FloorCheck` (Domain) reads bytes first, then inodes, and names the first floor crossed. A floor of exactly the free amount does not hold. A floor below 1 is refused when the service is built (`FloorRefused`).
- `StatFreeSpace` (Infrastructure) reads bytes with `disk_free_space()` and inodes with `stat --file-system --format=%d` through Symfony Process ([requirements](requirements.md)). A folder or tool that cannot be read throws `FreeSpaceUnreadable`, so the start fails visibly instead of passing a disk it could not measure.
- `BudgetGuard` asks `DiskHeadroom` after the watchdog halt (only the owner lifts a halt) and before the shared holds, for `decide()` and `decideMerge()`, because a merge writes the mirror and the ledger. A replayed day uses `ReplayedDisk`, which never holds.

No step or merge consults `StartGuard` yet (see [Measured use](measured-use.md)), so the hold is in the decision and its tests, and not yet a pause of live work.

## Settings

`config/services.yaml` holds `intakt.disk.watched_directory` (`var/`, where the mirrors, job folders, clones and database live), `intakt.disk.free_bytes_floor` (1 GiB) and `intakt.disk.free_inodes_floor` (10,000). See [When the disk runs low](../reference/user-manual.md#when-the-disk-runs-low).

## Not here

Showing the held state on the Status page, and pruning old per-head checkouts.
