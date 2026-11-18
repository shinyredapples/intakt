# The build lane's review cap

The `Core/Lanes` module holds the admission rule of the build lane's review cap (plan T56, issue #2573). The build lane itself (slots, Messenger, the Workflow state machine, the `urgent` exception, pause and drain) is not built yet, so nothing calls the rule today.

**Which heads count.** `Contract\HeadWait` is the closed set of phases a pushed head waits in:

| Case | Value | Counts against the review cap |
|---|---|---|
| `ForReviewer` | `for_reviewer` | yes: queued for a reviewer |
| `InReview` | `in_review` | yes: a reviewer is reading it |
| `ForCi` | `for_ci` | no |
| `ForRunner` | `for_runner` | no |
| `ForLanding` | `for_landing` | no |

`HeadWait::holdsTheReviewCap()` is the one home of that rule. A head waiting for CI, for a CI runner or for the landing step does not count, because no reviewer can move it and a cap filled with such heads would stop the builders while the review worker has nothing to review. A limit on all heads in flight, which would cover those heads, is not built (part 2 of issue #965).

**Admission.** `Domain\ReviewCap` takes the cap (the number of heads waiting for a reviewer at which builders stop) and refuses a value below 1 with `ReviewCapMustBePositive`. `admitsABuildBeside(list<HeadWait>)` counts the heads in flight that hold the cap and admits a build while that count is below the cap: with a cap of 2, one head in review beside any number of heads waiting for CI, a runner or landing admits a build; two heads waiting for a reviewer stop it.

Tests: `tests/Core/Lanes/Domain/ReviewCapTest.php`.
