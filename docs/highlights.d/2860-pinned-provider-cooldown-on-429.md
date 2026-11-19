## Symfony AI Platform

| `FailoverPlatform` | failover between the accounts of one provider inside Intakt's own wrapper, which skips cooling accounts and, for a model id that pins a router provider, accounts whose pinned provider cools down, cools down the account a limit came from (and its pinned provider, when the model pins one) and builds a new `FailoverPlatform` per call (it keys its limiter by platform class and calls a failed platform again, `upstream/ai-failover-platform-retries-a-failed-platform/`); a substitute provider is chosen in plain code, not through `FailoverPlatform` | in progress | T14 (#19, parts #414 and #415), #2860 (part of #2833), `src/Core/Ai/Infrastructure/AccountFailoverPlatform.php`, `src/Core/Ai/Infrastructure/AccountPlatform.php`, `src/Core/Ai/Infrastructure/TierPlatform.php` |

## Symfony components

| RateLimiter | per-account cooldown windows aligned to the vendor's reset: a `fixed_window` of one call anchored (`anchor_at`) at the reset, in a cache pool every worker shares, with Lock around each window; for an account whose pinned provider cools down, a running window that ends earlier is replaced (`reset()`, then a window anchored at the pin's end) | in progress | T14 (#19, part #414), #2860 (part of #2833), `src/Core/Ai/Infrastructure/AccountCooldownWindows.php`, `config/packages/cache.yaml` |
