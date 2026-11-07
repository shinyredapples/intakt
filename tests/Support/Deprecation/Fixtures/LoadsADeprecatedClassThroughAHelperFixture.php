<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation\Fixtures;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LoadsADeprecatedClassThroughAHelperFixture extends TestCase
{
    public function testItLoadsAClassThatIsDeprecatedThroughATestHelper(): void
    {
        self::assertTrue(new DeprecatedClassLoader()->load());
    }
}
