## Symfony components
| Clock `MonotonicClock` | measures the 15-second deadline of the forge check, so a system clock set back during the run does not lengthen it | in progress | #3866, `src/Plugin/GitHubForge/Infrastructure/DeadlineHttpClient.php` |
| Cache pool for the board's forge verdict | keeps the forge check's verdict for 60 seconds in a pool on `cache.app`, so a board load runs the check at most once a minute | in progress | #3866, `src/Core/Dashboard/Infrastructure/RememberedForgeVerdict.php` |
