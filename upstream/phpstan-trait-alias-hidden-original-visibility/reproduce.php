<?php

declare(strict_types=1);

use PHPStan\BetterReflection\BetterReflection;
use PHPStan\BetterReflection\Reflector\DefaultReflector;
use PHPStan\BetterReflection\SourceLocator\Type\SingleFileSourceLocator;

require_once dirname(__DIR__, 2).'/vendor/phpstan/phpstan/bootstrap.php';

$fixture = __DIR__.'/HiddenTest.php';

require $fixture;

$php = new ReflectionMethod('HiddenTest', 'testRuns')->isPublic();

$betterReflection = new BetterReflection();
$reflector = new DefaultReflector(new SingleFileSourceLocator($fixture, $betterReflection->astLocator()));
$better = $reflector->reflectClass('HiddenTest')->getMethod('testRuns')?->isPublic();

echo 'PHP: HiddenTest::testRuns() is public: ', var_export($php, true), PHP_EOL;
echo 'BetterReflection: HiddenTest::testRuns() is public: ', var_export($better, true), PHP_EOL;

// "testRuns as private" hides the original name even when another adaptation also renames the method, so both must agree.
if ($php !== $better) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
