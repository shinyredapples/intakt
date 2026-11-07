<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class HighlightsCommandTest extends TestCase
{
    public function testTheHighlightsCommandIsExecutable(): void
    {
        self::assertTrue(is_executable(__DIR__.'/../../bin/highlights'));
    }
}
