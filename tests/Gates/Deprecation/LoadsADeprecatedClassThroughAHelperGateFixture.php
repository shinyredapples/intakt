<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates\Deprecation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LoadsADeprecatedClassThroughAHelperGateFixture extends TestCase
{
    public function testItLoadsAClassThatIsDeprecatedThroughAGateTestHelper(): void
    {
        self::assertTrue(new DeprecatedClassLoader()->load());
    }
}
