# PHPStan remembers a readonly property's constructor type although `clone with` sets it

Versions: `phpstan/phpstan` 2.3.5 (Intakt's lock), also 2.3.6 and 2.3.x-dev a60e937; PHP 8.5.

## What happens

A readonly property that the constructor sets to `null` and a method sets through PHP 8.5's `clone ($this, ['mark' => $mark])` is still read as `null` in every other method. `MarkedRound.php` is the smallest shape: `isReplay()` compares the property with an enum case, and level max reports

```
MarkedRound.php:22:Strict comparison using === between Mark::Replay and null will always evaluate to false. [identifier=identical.alwaysFalse]
```

while at run time `new MarkedRound()->markedAs(Mark::Replay)->isReplay()` is `true`. A private initializer called from the constructor is refused instead (`property.readOnlyAssignNotInConstructor`), and a private getter typed `?Mark` is reported as never returning `Mark` (`return.unusedType`).

## Expected

No error: on PHP 8.5 `clone with` can give a readonly property another value, so the constructor's type is not the property's only possible type in other methods.

## Effect in Intakt

`src/Core/Dashboard/Domain/ReviewedRound.php` sets its replay mark to `null` in the constructor and marks a round only through `markedAs()` (cleanup #2417, item 1 of #2173). Its `isReplay()` reads the mark through a private `isReplayMarked(self $round)`, given `$this`, since PHPStan narrows only `$this->mark`, not the property of another instance. Once this passes, `isReplay()` compares `$this->mark` directly and the helper goes.

## Upstream status

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run`: `still fails  phpstan-clone-with-readonly-constructor-type`.
- **Development branch:** still fails on `phpstan/phpstan` 2.3.x-dev a60e937 (scratch copy, 2026-11-17).
- **Existing issue or pull request:** phpstan/phpstan#14838 (open, 2026-06-17), "false-positive error when read-only object is modified through php8.5 clone with", the same bug. Related: phpstan/phpstan#14841 (open), the same remembered type for classes with `__clone()`.
- **Pull requests and commits:** phpstan/phpstan-src#5893 "Do not remember readonly properties' constructor-narrowed types in other methods when `clone with` is available (PHP 8.5)" fixed this, but was closed without merging on 2026-09-20. Searched 2026-11-17 in `phpstan/phpstan` issues (`clone with readonly`, `clone with always evaluate`, `clone with remembered`, `readonly constructor narrow clone`) and in `phpstan/phpstan-src` issues and pull requests (`clone with readonly`).
- **Decision:** `already reported` as phpstan/phpstan#14838; nothing to file. Checked 2026-11-22: phpstan/phpstan#14838 and #14841 are still open.
