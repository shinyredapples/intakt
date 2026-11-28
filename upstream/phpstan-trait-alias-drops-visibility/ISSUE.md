Repository: `phpstan/phpstan`
Template: Bug report
Title: Trait method that one `use` block both makes private and aliases keeps the wrong visibility

### Bug report

When one `use` block changes the visibility of a trait method under its own name and also aliases it, PHPStan gives the method the wrong visibility:

- the method under its own name keeps the trait's visibility, so `testRuns as private;` is lost;
- an alias without a modifier takes the modifier of the other adaptation, so `testRuns as testAgain;` becomes private.

The cause is in the bundled BetterReflection (`ondrejmirtes/better-reflection` 6.75.0.6): `ReflectionClass::createMethodsFromTrait()` keeps one modifier per trait method (`traitsData['modifiers']`) and applies it to every alias, while the method under its own name keeps the trait's modifiers once it has an alias. `Roave/BetterReflection` `6.76.x` has the same lookup.

Versions: PHPStan 2.3.7 and `phpstan/phpstan-src` `2.3.x` at 63e9202, PHP 8.5.12.

### Code snippet that reproduces the problem

Playground link to be added by the reporter. The snippet, analysed with `phpstan analyse -l 2`:

```php
<?php declare(strict_types = 1);

trait Checks
{
	public function testRuns(): void {}
}

class HidesTheOriginal
{
	use Checks { testRuns as private; testRuns as protected testHidden; }
}

class KeepsTheAliasPublic
{
	use Checks { testRuns as private; testRuns as testAgain; }
}

(new HidesTheOriginal())->testRuns();
(new KeepsTheAliasPublic())->testAgain();
```

Actual output:

```
 ------ ------------------------------------------------------------------
  Line   snippet.php
 ------ ------------------------------------------------------------------
  19     Call to private method testAgain() of class KeepsTheAliasPublic.
         🪪  method.private
 ------ ------------------------------------------------------------------
```

### Expected output

The same visibility as PHP, which runs line 19 and stops at line 18 with `Call to private method HidesTheOriginal::testRuns() from global scope`:

```
  18     Call to private method testRuns() of class HidesTheOriginal.
```

and no error on line 19.

### Did PHPStan help you today? Did it make you happy in any way?

Left to the reporter.
