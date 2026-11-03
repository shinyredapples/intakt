# [Failover Platform] A failed platform is called again on the next call, whatever its rate limiter says

Draft, not posted. Versions: `symfony/ai-failover-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/rate-limiter` v8.1.10, PHP 8.5.12.

## What happens

`FailoverPlatform::do()` records a failed platform in `$failedPlatforms` and consumes a token of its limiter, but on the next call it invokes every platform in order again: the limiter's answer only decides whether the platform is removed from `$failedPlatforms`, never whether it is skipped, and `$failedPlatforms` is never read otherwise. A platform that is down is therefore called first on every call.

The limiter is also created with `$platform::class` as its key, and the OpenAi, Ollama and Anthropic factories all return `Symfony\AI\Platform\Platform`, so every platform shares one limiter (the documented Ollama-to-OpenAI example included), not only two accounts of one provider: a failure of one uses up the tokens of all. Successful calls consume a token too.

`reproduce.php` gives the platform a failing and a working platform of one class and a limiter of one token per hour, calls it twice and prints:

```
the failing platform was called 2 times, the working one 2 times
a failed platform is called again on the next call
```

## Expected

A platform that is in `$failedPlatforms` is skipped until its limiter allows a retry, so the failing platform is called once; and each platform has a limiter of its own (for example keyed by its position or a name), not one per class.

## Effect in Intakt

`src/Core/Ai/Infrastructure/AccountFailoverPlatform.php` picks the accounts that are not cooling down itself and builds a `FailoverPlatform` over only those for each call, with a limiter that never limits.

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-failover-platform-retries-a-failed-platform`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-failover-platform` 8ae86c0c7624, `symfony/ai-platform` 17c452fa350f): `still fails  ai-failover-platform-retries-a-failed-platform`, the failing platform still called 2 times.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #1953 (open issue, a `FailoverProvider` to replace `FailoverPlatform`; does not mention the limiter), #1126 (closed pull request that added `FailoverPlatform`), #1391 (closed issue, HTTP 403 not passed to the next platform), #1808 and #1864 (open pull requests, model override and model failover).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `FailoverPlatform`, `failover rate limiter`, `failedPlatforms`.
- **Decision:** `ready to file`.
- **Pull request search (D12, 2026-11-03, `main` 17d320e7beee):** open with the prefix `[Platform][Failover]`: #1808 (`Add support for model override`); open touching `src/platform/src/Bridge/Failover/` or the failover class: #1808, and #1864 (`[Platform] Add ModelFailoverPlatform bridge`, a new `ModelFailover` bridge folder, no `Failover` prefix); merged since BETA1 on `src/platform/src/Bridge/Failover/`: none.
- **Fix pull request:** `issue only`. The expected behavior is a design choice (skip a platform while its limiter has no room, and how each platform's limiter is keyed), and open pull requests #1808 and #1864 (checked 2026-11-03 against `main` 17d320e7beee) rewrite the constructor and the platform list of the same class, so a fix would conflict with them. Neither fixes the retry.
- **Owner review (2026-11-03, `main` 17d320e7):** #1953 plans a `FailoverProvider` to replace this class, so the bug goes upstream as a comment there, so that the replacement does not repeat it. The limiter key is `$platform::class` and every bridge factory returns `Platform`, so all platforms share one limiter, and successful calls consume a token too. (The Ollama bridge is not installed in Intakt; its factory was checked on `main` by the owner session.)
- **Status:** `ready to file` as a comment for #1953, in `COMMENT.md`.
