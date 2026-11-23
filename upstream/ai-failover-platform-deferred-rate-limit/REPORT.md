# [Failover Platform] A 429 that surfaces when the result is read never reaches the next platform

Filed as symfony/ai#2944 on 2026-11-14. Versions: `symfony/ai-failover-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/ai-generic-platform` v1.0.0-BETA1, `symfony/rate-limiter` v8.1.11, PHP 8.5.12.

## What happens

`FailoverPlatform::do()` moves on to the next platform only when `invoke()` throws. A bridge's HTTP client is lazy: `invoke()` returns a `DeferredResult` without looking at the response, and the bridge's result converter raises `RateLimitExceededException` for a 429 only when the result is read (`asText()`, `getResult()`). By then `FailoverPlatform` has returned the first platform's result, so the second platform is never asked and the caller gets the 429.

`DeferredRateLimitFailoverTest.php` runs two accounts of one provider as two Generic platforms over `FailoverPlatform`. The first account's 429 (with `retry-after: 30`) and the second account's answer are replayed from the redacted cassettes in `cassettes/`, written through `CassetteHttpClient` from scripted HTTP answers, not from a live provider. The Generic result converter passes `null` as the retry-after value of `RateLimitExceededException`, so the `retry-after: 30` header does not reach the exception. Run alone (`vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php upstream/ai-failover-platform-deferred-rate-limit`):

```
 ✘ A rate limit that surfaces when the result is read fails over to the second platform
   │ Symfony\AI\Platform\Exception\RateLimitExceededException: Rate limit exceeded. Rate limit reached for requests
 ✔ A rate limit raised while invoking fails over to the second platform
 ✘ Two platforms of one class have rate limit keys of their own
   │ Both platforms share the rate-limit key Symfony\AI\Platform\Platform
```

The second test is the control: the same 429 raised inside `invoke()` (a `MockPlatformFactory` platform whose `ScriptedResponse` closure throws) fails over as documented. The third test is the shared limiter key of two platforms of one class, already reported in `upstream/ai-failover-platform-retries-a-failed-platform/` and fixed by the open symfony/ai#2890; it stays here because two accounts of one provider are exactly that case.

## Expected

A provider error that a platform reports through its `DeferredResult` fails over like one thrown by `invoke()`: the second platform answers and `asText()` returns its answer. A failover that reads the first result itself loses the laziness of streaming and of the caller's own error handling, so how to fail over late is a design choice of the failover (for example a `DeferredResult` that tries the next platform when the conversion of the first fails, before any delta reached the caller).

## Effect in Intakt

`src/Core/Ai/Infrastructure/AccountPlatform.php` hangs the account's cooldown on the result's `onError()`, so the limited account is skipped on the next call, and the review is started again on the other account (`docs/architecture/ai-providers.md`, `docs/architecture/vendor.md`). The call that met the 429 still fails; Intakt does not rely on `FailoverPlatform` for it.

## Upstream status

- **Pinned versions:** still fails (two of three tests, as above).
- **Development branch:** still fails, the same two tests. Run in a scratch copy of `vendor/` with `src/platform`, `src/agent` and the Failover and Generic bridges of `symfony/ai` `main` e2b5e8476ead swapped in. On 2026-11-16, at the head of the open symfony/ai#2890 (cf789ecd0822, `FailoverPlatform.php` only), the shared key test passes and the 429 read from the result still fails.
- **Earlier check:** 2026-11-12 against `symfony/ai` `main` e2b5e8476ead and the newest release v1.0.0-BETA1 (f620d0fc6790); duplicate search in `symfony/ai` issues and pull requests, open and closed, with the terms `FailoverPlatform`, `failover DeferredResult`, `failover 429`, `failover rate limit`. Found: #2890 (open pull request, the retry and the shared key; does not read the result), #1953 (open issue, a `FailoverProvider` to replace this class; its sketch fails over only on errors thrown by `invoke()`), #1391 (closed issue, a 403 not passed to the next platform, thrown on invoke), #1808 and #1864 (open pull requests, model override and model failover). None covers an error that surfaces when the result is read.
- **Pull requests and commits:** none fixes it (checked 2026-11-15 at `symfony/ai` `main` 999d5fbeea73). Pull requests, open, merged and closed, searched with `is:pr FailoverPlatform`, `is:pr Failover rate limit`, `is:pr Failover DeferredResult`, `is:pr Failover 429` and `is:pr Failover stream`: #2890 (open, the retry and the shared key; still does not read the result), #1864 and #1808 (open, model failover and model override), #1452 (open, load balanced platform), #1923 (merged, provider abstraction), #1126 (merged, the original `FailoverPlatform`). Commits: `git log v1.0.0-BETA1..origin/main -- src/platform/src/Bridge/Failover` lists none.
- **Retry-After:** the dropped `retry-after` header noted above is a separate gap, covered by the open symfony/ai#2913 (`[Platform][Generic] Read the retry-after header of a rate limited completion`, seen 2026-11-19). `GenericRetryAfterHeaderTest.php` reproduces it alone: a `MockHttpClient` 429 with `retry-after: 137` sent through `Generic\Factory::createPlatform` throws a `RateLimitExceededException` whose `getRetryAfter()` is null (the test fails until the header reaches the exception). Until it ships, Intakt reads the header from the raw answer itself (`AccountPlatform`, issue #2860).
- **Form:** an issue. `CONTRIBUTING.md` of `symfony/ai` sends bug reports to its issue tracker, and every pull request targets `main` (no maintenance branches).
- **Fix pull request:** `issue only`. How to fail over after `invoke()` returned is a design choice (see Expected), and #2890 rewrites the same loop in `do()`, so a fix now would conflict with it.
- **Checked:** 2026-11-16 against `symfony/ai` `main` 999d5fbeea73 (2026-11-15) and the newest release v1.0.0-BETA1 (f620d0fc6790, tag object 32a562fa); duplicate search in `symfony/ai` issues and pull requests, open, merged and closed, with the terms `FailoverPlatform`, `failover DeferredResult`, `failover 429`, `failover rate limit`, `Failover deferred`, `failover lazy result`; commits on `main` since BETA1 touching `src/platform/src/Bridge/Failover`, `Result/DeferredResult.php`, `Generic/Completions/ResultConverter.php` (none; the only hit is merge 3d68886e, whose diff to BETA1 is empty for these paths). Found: #2944 (open issue, this report, filed 2026-11-14 by haraldpdl), #2890 (open pull request, retry and shared key, not merged), #1953 (open issue, `FailoverProvider`), #1391 (closed, 403 thrown on invoke), #1808, #1864, #1716, #1452 (open, model override / model failover / configurable fallback / load balancing), #1126 (merged, added FailoverPlatform), #1401 (closed).
- **Decision:** `filed as symfony/ai#2944` (https://github.com/symfony/ai/issues/2944), filed 2026-11-14 from the owner's account as a new issue with the text of `ISSUE.md`, which names #2890 and #1953 in one line each; open, no replies on 2026-11-16.
- **Status:** recheck #2944 for replies, and #2890 (still open at head cf789ecd0822; it does not touch this bug). Checked 2026-11-22: #2944 is open, label `Platform`, no replies; #2890 is still open (approved by a reviewer on 2026-11-21, not merged).
