<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge\Contract;

use Intakt\Plugin\Forge\Contract\CommentBodies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommentBodies::class)]
final class CommentBodiesTest extends TestCase
{
    public function testCommentBodiesKeepTheOrderTheyArePostedIn(): void
    {
        self::assertSame(['first ask', 'second ask'], [...new CommentBodies('first ask', 'second ask')]);
    }

    public function testNoCommentBodiesIterateAsNothing(): void
    {
        self::assertSame([], [...new CommentBodies()]);
    }
}
