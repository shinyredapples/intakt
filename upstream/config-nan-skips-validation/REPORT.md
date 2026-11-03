# [Config] A NaN value skips type checks, range checks and validation closures

Draft, not posted. Versions: `symfony/config` v8.1.5, PHP 8.5.12.

## What happens

`BaseNode::normalize()` and `BaseNode::finalize()` detect a placeholder with `$value !== $placeholders = self::resolvePlaceholderValue($value)`. For `NAN`, `resolvePlaceholderValue()` returns the value unchanged, but `NAN !== NAN` is true, so the node takes the placeholder branch: it iterates over a float (a `foreach() argument must be of type array|object` warning) and returns the value without its type check, `min()`/`max()` or any `validate()` closure. YAML's `.NaN` reaches a `floatNode('ceiling')->min(1)->max(100)` this way:

```
NaN passed min, max and the validation closure: NAN
```

The same holds for every ancestor array node, since an array holding `NAN` is not identical to itself either. Only `beforeNormalization()` closures still run.

## Expected

The placeholder check compares in a way that holds for NaN (for example `is_float($value) && is_nan($value)` taken as no placeholder), so `min()`/`max()` and validation closures see the value and refuse it.

## Effect in Intakt

`src/Core/Budget/Infrastructure/CeilingsConfiguration.php` turns a NaN ceiling into a string in a `beforeNormalization()` closure, so the float type check refuses it; otherwise a `.NaN` ceiling would never be reached and a call would start at any measured use.

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/config` v8.1.5: `still fails  config-nan-skips-validation`.
- **Development branch:** still fails on both branches. The same run in a scratch copy with `symfony/config` at `8.1.x-dev` (4a0e7dd1645b) and, in another, every Symfony component at `8.2.x-dev` (`symfony/config` 0d10198cc52a): `still fails  config-nan-skips-validation` in each.
- **Existing issue or pull request:** none found. Candidates in `symfony/symfony`, none the same bug: #23888 (closed pull request of 2017, validation of env vars in config, where `resolvePlaceholderValue()` comes from), #65906 (closed pull request, S/MIME cipher configuration). The `NaN config` hits are YAML and release pull requests.
- **Searches** (`symfony/symfony` issues and pull requests, open and closed): `NaN config`, `resolvePlaceholderValue`, `label:Config NAN`, `BaseNode NAN`.
- **Decision:** `ready to file`.
