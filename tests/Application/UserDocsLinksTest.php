<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// A broken relative link in the user docs sends a reader to a page that does not exist.
#[CoversNothing]
final class UserDocsLinksTest extends TestCase
{
    public function testTheTimedRunLinkInTheUserDocsPointsAtAnExistingFile(): void
    {
        $root = dirname(__DIR__, 2);
        $text = file_get_contents($root.'/docs/user/README.md');

        self::assertSame(1, preg_match('/\[README\]\(([^#)]+)#timed-run\)/', $text, $match));
        self::assertFileExists($root.'/docs/user/'.$match[1]);
    }
}
