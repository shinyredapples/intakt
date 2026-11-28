# [BetterReflection] A trait method that one `use` both aliases and changes the visibility of gets the wrong visibility

Draft, not posted. Versions: `phpstan/phpstan` 2.3.7 (the `ondrejmirtes/better-reflection` fork bundled in its phar), PHP 8.5.12.

## What happens

`ReflectionClass::createMethodsFromTrait()` keeps one modifier per trait method (`traitsData['modifiers']`, keyed by trait and method) and applies it like this:

- when the method also has an alias, the method under its own name keeps the trait's visibility, so `testRuns as private;` is lost;
- every alias of the method gets that one stored modifier, so an alias without a modifier takes the visibility of another adaptation of the same method.

`reproduce.php` compares BetterReflection with PHP's own reflection:

```
HidesTheOriginal::testRuns(): PHP says not public, BetterReflection says public
KeepsTheAliasPublic::testAgain(): PHP says public, BetterReflection says not public
```

## Expected

The same visibility as PHP: a visibility-only adaptation (`testRuns as private;`) changes the method under its own name, an alias takes its own modifier, and an alias without one keeps the trait method's visibility.

## Effect in Intakt

The `intakt.testMethodSentence` PHPStan rule judges trait test methods by their visibility in the using class. `TraitAliasCollector` reads the visibility a class gives a trait method from the `use` block it visits instead of from BetterReflection, so this bug does not change its findings.

## Upstream status

- **Pinned versions:** still fails. `php upstream/phpstan-trait-alias-drops-visibility/reproduce.php` on `phpstan/phpstan` 2.3.7 prints the two lines above and exits with 1.
- **Development branch:** still fails on `phpstan/phpstan` 2.3.x-dev 6955c1d (built from `phpstan/phpstan-src` 6955c1d4), run in a scratch Composer project with `nikic/php-parser` 5.9.0, 2026-11-28: the same two lines, exit 1. `phpstan/phpstan-src` `2.3.x` still pins `ondrejmirtes/better-reflection` 6.75.0.6, the version 2.3.7 bundles, and the fork's `6.75.x` head (f95c617) holds no commit after that tag. `createMethodsFromTrait()` in `Roave/BetterReflection` `6.76.x` keeps the same one-modifier-per-method lookup.
- **Existing issue or pull request:** none for this case. Related and closed: Roave/BetterReflection#663 (a single visibility change or alias ignored, fixed 2021), phpstan/phpstan#3465 and #6264 (one adaptation per `use`), phpstan/phpstan#12544 (calling the original name of an aliased method), Roave/BetterReflection#1460 (merged 2024-11-16: a method missing when several aliases are used). None covers a `use` that both changes the visibility of a method under its own name and aliases it.
- **Checked:** 2026-11-28, `phpstan/phpstan` default branch `2.3.x` at 6955c1d (`phpstan/phpstan-src` `2.3.x` at 63e9202) and the newest release 2.3.7. Searched: the existing `upstream/` folders (only `phpstan-clone-with-readonly-constructor-type`, another bug); pull requests, open, merged and closed, in `phpstan/phpstan-src`, `ondrejmirtes/BetterReflection` and `Roave/BetterReflection` (`trait alias visibility`, `createMethodsFromTrait`, `trait alias`, `trait visibility`, `trait`); the commits on `src/Reflection/ReflectionClass.php` of `ondrejmirtes/BetterReflection` `6.75.x` and `Roave/BetterReflection` `6.76.x`, and the 219 commits of `phpstan/phpstan-src` from 2.3.7 to `2.3.x` (none changes trait adaptations in reflection); issues, open and closed, in `phpstan/phpstan` (`trait alias visibility`, `createMethodsFromTrait`, `trait "as private"`, `trait alias "as protected"`) and `Roave/BetterReflection` (`trait alias visibility`, `trait alias modifier`, `createMethodsFromTrait`).
- **Pull requests and commits:** none found (checked 2026-11-28).
- **Preferred form and target branch:** a fix pull request to `ondrejmirtes/BetterReflection` on its default branch `6.75.x`, followed by a bump of the pin in `phpstan/phpstan-src` `2.3.x`; otherwise an issue on `phpstan/phpstan`. Source: the `phpstan/phpstan` README ("PHPStan's source code open to pull requests lives at `phpstan/phpstan-src`"), the `phpstan/phpstan-src` README (issues go to `phpstan/phpstan`), and the fork's repository settings (issues disabled, pull requests accepted, default branch `6.75.x`).
- **Decision:** `ready to file`; nothing is posted without the owner's approval.
