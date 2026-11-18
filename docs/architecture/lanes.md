# The build lane's review cap and in-flight limit

The `Core/Lanes` module holds the two admission rules of the build lane (plan T56): the review cap (issue #2573) and the limit on all heads in flight (issue #2574). The build lane itself (slots, Messenger, the Workflow state machine, the `urgent` exception, pause and drain) is not built yet, so nothing calls either rule today, and no configuration key sets them. The design states both limits and why they differ in `docs/design/05-architecture/03-choosing-work.md` (step 5).

**Which heads count.** `Contract\HeadWait` is the closed set of phases a pushed head waits in:

| Case | Value | Counts against the review cap | Counts against the in-flight limit |
|---|---|---|---|
| `ForReviewer` | `for_reviewer` | yes: queued for a reviewer | yes |
| `InReview` | `in_review` | yes: a reviewer is reading it | yes |
| `ForCi` | `for_ci` | no | yes |
| `ForRunner` | `for_runner` | no | yes |
| `ForLanding` | `for_landing` | no | yes |

`HeadWait::holdsTheReviewCap()` is the one home of the review cap's rule. A head waiting for CI, for a CI runner or for the landing step does not count against the cap, because no reviewer can move it and a cap filled with such heads would stop the builders while the review worker has nothing to review. Those heads still count against the in-flight limit, which counts every head it is given, whatever its case.

**Review cap.** `Domain\ReviewCap` takes the cap (the number of heads waiting for a reviewer at which builders stop) and refuses a value below 1 with `ReviewCapMustBePositive`. `admitsABuildBeside(list<HeadWait>)` counts the heads in flight that hold the cap and admits a build while that count is below the cap: with a cap of 2, one head in review beside any number of heads waiting for CI, a runner or landing admits a build; two heads waiting for a reviewer stop it.

**In-flight limit.** `Domain\HeadsInFlightLimit` takes the limit (the number of pushed, unmerged heads at which builders stop) and refuses a value below 1 with `HeadsInFlightLimitMustBePositive`. `HeadsInFlightLimit::twiceThe(ReviewCap)` is the one home of the default, twice the review cap. `admitsABuildBeside(list<HeadWait>)` admits a build while fewer heads than the limit are in flight: with a cap of 2 and the default limit of 4, two heads waiting for CI, one waiting for landing and one in review stop a build although the review cap still admits it. Nothing combines the two rules yet; by the design, the build lane will start a build only while both admit it. The limit is higher than the cap because it bounds a different cost: heads waiting for CI or landing leave the reviewer free, but each needs a CI run and may conflict with the others when they land.

Tests: `tests/Core/Lanes/Domain/ReviewCapTest.php`, `tests/Core/Lanes/Domain/HeadsInFlightLimitTest.php`.
