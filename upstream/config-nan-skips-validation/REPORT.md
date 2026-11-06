# [Config] A NaN value skips type checks, range checks and validation closures

Draft, not posted. Versions: `symfony/config` v8.1.5, PHP 8.5.12.

## What happens

`BaseNode::normalize()` and `BaseNode::finalize()` detect a placeholder with `$value !== $placeholders = self::resolvePlaceholderValue($value)`. For `NAN`, `resolvePlaceholderValue()` returns the value unchanged, but `NAN !== NAN` is true, so the node takes the placeholder branch: it iterates over a float (a `foreach() argument must be of type array|object` warning) and returns the value without its type check or any `validate()` closure. YAML's `.NaN` reaches a `floatNode('ceiling')->min(1)->max(100)` this way:

```
NaN passed min, max and the validation closure: NAN
```

`min()` and `max()` would not refuse it after a fix either, since `NAN < min` and `NAN > max` are both false; only a `validate()` closure catches it. The parent array nodes are not affected: `resolvePlaceholderValue()` returns the same array, which PHP treats as identical to itself even when it holds a NaN. Only `beforeNormalization()` closures still run.

## Expected

The placeholder check runs only for strings (`resolvePlaceholderValue()` resolves nothing else), so the type check and the validation closures see a NaN like any other float.

## Effect in Intakt

`src/Core/Budget/Infrastructure/CeilingsConfiguration.php` turns a NaN ceiling into a string in a `beforeNormalization()` closure, so the float type check refuses it; otherwise a `.NaN` ceiling would never be reached and a call would start at any measured use.

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/config` v8.1.5: `still fails  config-nan-skips-validation`.
- **Development branch:** still fails on both branches. The same run in a scratch copy with `symfony/config` at `8.1.x-dev` (4a0e7dd1645b) and, in another, every Symfony component at `8.2.x-dev` (`symfony/config` 0d10198cc52a): `still fails  config-nan-skips-validation` in each.
- **Existing issue or pull request:** none found. Candidates in `symfony/symfony`, none the same bug: #23888 (closed pull request of 2017, validation of env vars in config, where `resolvePlaceholderValue()` comes from), #65906 (closed pull request, S/MIME cipher configuration). The `NaN config` hits are YAML and release pull requests.
- **Searches** (`symfony/symfony` issues and pull requests, open and closed): `NaN config`, `resolvePlaceholderValue`, `label:Config NAN`, `BaseNode NAN`.
- **Decision:** `ready to file`.
- **Owner review (2026-11-03, symfony/symfony 6.4 to 8.2):** an issue. The fix looks small: guard the four `resolvePlaceholderValue()` comparisons in `BaseNode` with `\is_string($value)`. "min()/max() refuse it" is dropped (only a `validate()` closure catches a NaN after the fix). The owner session expected a misleading "A dynamic value is not compatible" error from the root when the NaN has non-NaN siblings; that does not reproduce on v8.1.5 or 8.2.x-dev (0d10198cc52a) with scalar, prototyped or nested array siblings, in one config or two (scratch scripts, 2026-11-03): the NaN passes silently in every case, so the issue says that. `reproduce.php` also fails with `symfony/config` v6.4.49 and v7.4.22.
- **Status:** `recheck needed before filing`: the report has no "Checked" line, so the owner redoes the dated check against upstream first (see `upstream/README.md`). The issue text is `ISSUE.md`.
