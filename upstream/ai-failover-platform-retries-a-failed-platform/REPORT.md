# [Failover Platform] A failed platform is called again on the next call, whatever its rate limiter says

Fix pull request open: symfony/ai#2890. Versions: `symfony/ai-failover-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/rate-limiter` v8.1.10, PHP 8.5.12.

## What happens

`FailoverPlatform::do()` records a failed platform in `$failedPlatforms` and consumes a token of its limiter, but on the next call it invokes every platform in order again: the limiter's answer only decides whether the platform is removed from `$failedPlatforms`, never whether it is skipped, and `$failedPlatforms` is never read otherwise. A platform that is down is therefore called first on every call.

The limiter is also created with `$platform::class` as its key, and the OpenAi, Ollama and Anthropic factories all return `Symfony\AI\Platform\Platform`, so every platform shares one limiter (the documented Ollama-to-OpenAI example included), not only two accounts of one provider: a failure of one uses up the tokens of all. Successful calls consume a token too.

`reproduce.php` shows the two apart. First it gives the platform a failing and a working platform of two different classes (so each has a limiter of its own) and a limiter of one token per hour, and calls it twice. Then it records the limiter keys of one call over two failing platforms of one class and a working one. It prints:

```
retry: the failing platform was called 2 times, the working one 2 times
a failed platform is called again on the next call
limiter keys of two failing platforms and a working one: FailingPlatform, FailingPlatform, WorkingPlatform
two platforms of one class share one limiter
```

## Expected

A platform that is in `$failedPlatforms` is skipped until its limiter allows a retry, so the failing platform is called once; and each platform has a limiter of its own (for example keyed by its position or a name), not one per class.

## Effect in Intakt

`src/Core/Ai/Infrastructure/AccountFailoverPlatform.php` picks the accounts that are not cooling down itself and builds a `FailoverPlatform` over only those for each call, with a limiter that never limits.

## Upstream status

- **Checked:** 2026-11-14. Ran against the default branch `main` of `symfony/ai` (c4cb5da): still fails, the failing platform is called 2 times and the limiter keys are `FailingPlatform, FailingPlatform, WorkingPlatform`. The newest release, v1.0.0-BETA1, fails the same way: `php bin/console intakt:upstream:run` prints `still fails  ai-failover-platform-retries-a-failed-platform`. At the head of #2890 (cf789ecd0822) the reproduction passes.
- **Existing issue or pull request:** #1953 (the stalled `FailoverProvider` plan) does not mention the limiter; no issue was filed, the fix pull request below replaces it.
- **Pull requests and commits:** the open #2890 (`[Platform][Failover] Skip a failed platform and limit each platform on its own`, created 2026-11-06, updated 2026-11-14, head cf789ecd0822) fixes both cases (checked 2026-11-15 at `symfony/ai` `main` 1220483ebd24). With `src/platform` of its head swapped into a scratch copy of `vendor/`, `reproduce.php` prints `the failing platform was called 1 times`, limiter keys `0, 1, 2` and `REPRODUCTION PASSES`; at `main` it still fails. #1808 and #1864 are still open, #1452 (load balanced platform) is open too. Searched with `is:pr FailoverPlatform` and `is:pr failover rate limiter`. Commits: `git log v1.0.0-BETA1..origin/main -- src/platform/src/Bridge/Failover/` lists none.
- **Follow-up commit (2026-11-14):** cf789ecd answers the review of #2890: a platform skipped because its limiter has no room gets a last try when no other platform answers, instead of no try at all.
- **Status:** `fix pull request open` (symfony/ai#2890); wait for its review and merge. Once a release carries it: record that release here, and revisit `src/Core/Ai/Infrastructure/AccountFailoverPlatform.php`. Checked 2026-11-22: #2890 is still open at head cf789ecd0822; a reviewer approved it on 2026-11-21 and it carries the `Status: Reviewed` label; not merged yet.
