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

Not filed; not yet checked against PHPStan's `2.2.x` branch or searched for duplicates.
