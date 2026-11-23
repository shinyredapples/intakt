# [Config] A NaN value skips type checks, range checks and validation closures

Filed as pull request symfony/symfony#67911 (merged into 6.4 on 2026-11-06, released in v8.2.0-BETA2). Versions: `symfony/config` v8.1.5, PHP 8.5.12. Affected: 6.4 through 8.2.0-BETA1 (7.4.22, 8.1.11). Target branch: 6.4 (7.4 once 6.4 stops taking bug fixes at the end of November 2026).

## What happens

`BaseNode::normalize()` and `BaseNode::finalize()` detect a placeholder with `$value !== $placeholders = self::resolvePlaceholderValue($value)`. For `NAN`, `resolvePlaceholderValue()` returns the value unchanged, but `NAN !== NAN` is true, so the node takes the placeholder branch: it iterates over a float (a `foreach() argument must be of type array|object` warning) and returns the value without its type check or any `validate()` closure. YAML's `.NaN` reaches a `floatNode('ceiling')->min(1)->max(100)` this way:

```
NaN passed min, max and the validation closure: NAN
```

`min()` and `max()` would not refuse it after a fix either, since `NAN < min` and `NAN > max` are both false; only a `validate()` closure catches it. The parent array nodes are not affected: `resolvePlaceholderValue()` returns the same array, which PHP treats as identical to itself even when it holds a NaN. Only `beforeNormalization()` closures still run. On PHP 8.5 with 8.2 the run also prints "unexpected NAN value was coerced to bool" as an extra warning.

## Expected

The placeholder check runs only for strings (`resolvePlaceholderValue()` resolves nothing else), so the type check and the validation closures see a NaN like any other float.

## Effect in Intakt

`src/Core/Budget/Infrastructure/BudgetConfiguration.php` turns a NaN ceiling into a string in a `beforeNormalization()` closure, so the float type check refuses it; otherwise a `.NaN` ceiling would never be reached and a call would start at any measured use.

## Upstream status

Checked on 2026-11-02, refreshed on 2026-11-06 against 6.4, 7.4, 8.1 and 8.2 (8.2.0-BETA1).

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/config` v8.1.5: `still fails  config-nan-skips-validation`.
- **Development branch:** still fails on both branches. The same run in a scratch copy with `symfony/config` at `8.1.x-dev` (4a0e7dd1645b) and, in another, every Symfony component at `8.2.x-dev` (`symfony/config` 0d10198cc52a): `still fails  config-nan-skips-validation` in each.
- **Existing issue or pull request:** none found. Candidates in `symfony/symfony`, none the same bug: #23888 (closed pull request of 2017, validation of env vars in config, where `resolvePlaceholderValue()` comes from), #65906 (closed pull request, S/MIME cipher configuration). The `NaN config` hits are YAML and release pull requests.
- **Searches** (`symfony/symfony` issues and pull requests, open and closed): `NaN config`, `resolvePlaceholderValue`, `label:Config NAN`, `BaseNode NAN`.
- **Refresh (2026-11-06):** still reproduces and is not fixed upstream on 6.4, 7.4.22, 8.1.11 or 8.2.0-BETA1. The `\is_string($value) &&` guard stays. The report targets 6.4 (7.4 once 6.4 stops taking bug fixes at the end of November 2026).
- **Intakt lock (2026-11-16):** passes. `php bin/console intakt:upstream:run` at `symfony/config` v8.2.0-BETA2: `passes       config-nan-skips-validation`, `reproduce.php` prints `The value NAN is not allowed for path "root.ceiling". Should be a number` and `REPRODUCTION PASSES`. Fixed in 8.2.0-BETA2 (8.2.0-BETA1 still failed, see the refresh above); fixed by symfony/symfony#67911 (merged into 6.4 on 2026-11-06). 6.4 and 7.4 were not rerun.
- **Pull requests and commits:** filed as pull request #67911 by Intakt (merged 2026-11-06, bug fix `[Config] Do not treat NAN as a placeholder`), commit d2f88e779b on 6.4, merged up into 7.4, 8.1 and 8.2 (checked 2026-11-15 at `symfony/symfony` 8.2 979969dc83). It guards the four `resolvePlaceholderValue()` comparisons in `BaseNode` with `\is_string()`, the fix this report proposes, and makes `NumericNode` reject NAN against a minimum or maximum. Also merged the same day: #67927 (`EnumNode` messages for INF and NAN). Pull requests searched, open, merged and closed, with `is:pr Config NAN` and `is:pr NAN placeholder`; commits from `git log origin/<branch> -- src/Symfony/Component/Config/Definition/BaseNode.php` on 6.4, 7.4, 8.1 and 8.2.
- **Decision:** `filed as pull request` https://github.com/symfony/symfony/pull/67911, merged into 6.4 on 2026-11-06, released in v8.2.0-BETA2 (recorded 2026-11-20).
- **Owner review (2026-11-03, symfony/symfony 6.4 to 8.2):** an issue. The fix looks small: guard the four `resolvePlaceholderValue()` comparisons in `BaseNode` with `\is_string($value)`. "min()/max() refuse it" is dropped (only a `validate()` closure catches a NaN after the fix). The owner session expected a misleading "A dynamic value is not compatible" error from the root when the NaN has non-NaN siblings; that does not reproduce on v8.1.5 or 8.2.x-dev (0d10198cc52a) with scalar, prototyped or nested array siblings, in one config or two (scratch scripts, 2026-11-03): the NaN passes silently in every case, so the issue says that. `reproduce.php` also fails with `symfony/config` v6.4.49 and v7.4.22.
- **Status:** `filed as pull request` symfony/symfony#67911, merged into 6.4 on 2026-11-06, released in v8.2.0-BETA2 (recorded 2026-11-20): nothing more to file; `ISSUE.md` was removed. Checked 2026-11-22: still merged and in v8.2.0-BETA2, nothing more to watch.
