<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// Because a broken relative link sends a reader to a page that does not exist.
#[CoversNothing]
final class UserDocsLinksTest extends TestCase
{
    public function testTheTimedRunLinkInTheUserManualPointsAtAnExistingFile(): void
    {
        $root = dirname(__DIR__, 2);
        $text = (string) file_get_contents($root.'/docs/reference/user-manual.md');

        $found = preg_match('/\[timed run\]\(([^#)]+)#timed-run\)/', $text, $match);

        self::assertSame(1, $found);
        self::assertArrayHasKey(1, $match);
        self::assertFileExists($root.'/docs/reference/'.$match[1]);
    }
}
