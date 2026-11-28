# BetterReflection keeps a trait method public that `as private` hides when the class also renames it

Draft, not filed. Versions: `phpstan/phpstan` 2.3.7 (Intakt's lock, with its bundled `ondrejmirtes/better-reflection`), PHP 8.5.12.

## What happens

`HiddenTest.php` takes `testRuns()` from a trait twice: `testRuns as private;` hides the original name, and `testRuns as private testHidden;` adds a private alias. PHP hides both names. BetterReflection reports the original name as public, because `ReflectionClass::createMethodsFromTrait()` copies the method with its original modifiers whenever an alias with a new name exists for it, and so drops the visibility-only adaptation.

`reproduce.php` asks PHP's reflection and BetterReflection whether `HiddenTest::testRuns()` is public and prints:

```
PHP: HiddenTest::testRuns() is public: false
BetterReflection: HiddenTest::testRuns() is public: true
```

## Expected

Both report `false`: the visibility-only adaptation applies to the original name, the renaming one to the alias.

## Effect in Intakt

The `intakt.testMethodSentence` gate judges the original name of a trait method a test class renames while PHPUnit still runs it. With this bug it would report `testRuns()` in such a class although PHPUnit does not run a private method. `TraitAliasTestMethodCollector::isHidden()` reads the `use` block itself and skips a name an alias without a new name makes protected or private (`tests/Gates/TraitAliasTestMethodCollectorTest.php`, fixture `test-method-alias-hidden`).

## Upstream status

- **Pinned versions:** still fails (`php bin/console intakt:upstream:run`).
- **Pull requests and issues:** not searched from this build's sandbox; the duplicate check is the owner's before anything is filed.
- **Status:** `draft`, waiting for the owner's duplicate check.
