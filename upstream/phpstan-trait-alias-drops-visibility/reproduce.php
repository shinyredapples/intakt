<?php

declare(strict_types=1);

// Run from the repository root: php upstream/phpstan-trait-alias-drops-visibility/reproduce.php

use PHPStan\BetterReflection\BetterReflection;
use PHPStan\BetterReflection\Reflector\DefaultReflector;
use PHPStan\BetterReflection\SourceLocator\Type\StringSourceLocator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$code = <<<'PHP'
    <?php
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
    PHP;

eval(substr($code, 5));

$betterReflection = new BetterReflection();
$reflector = new DefaultReflector(new StringSourceLocator($code, $betterReflection->astLocator()));
$failed = false;

foreach (['HidesTheOriginal' => 'testRuns', 'KeepsTheAliasPublic' => 'testAgain'] as $class => $method) {
    $php = (new ReflectionMethod($class, $method))->isPublic() ? 'public' : 'not public';
    $better = $reflector->reflectClass($class)->getMethod($method)?->isPublic() ? 'public' : 'not public';
    $failed = $failed || $php !== $better;

    printf("%s::%s(): PHP says %s, BetterReflection says %s\n", $class, $method, $php, $better);
}

exit($failed ? 1 : 0);
