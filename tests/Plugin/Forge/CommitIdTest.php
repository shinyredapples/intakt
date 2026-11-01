<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\CommitId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommitId::class)]
final class CommitIdTest extends TestCase
{
    public function testAFullHashIsACommitId(): void
    {
        $id = new CommitId(str_repeat('ab', 20));

        self::assertTrue($id->equals(new CommitId(str_repeat('ab', 20))));
        self::assertFalse($id->equals(new CommitId(str_repeat('cd', 20))));
    }

    public function testAnAbbreviatedHashIsNotACommitId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommitId('abcdef1');
    }

    public function testAHashWithATrailingNewlineIsNotACommitId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommitId(str_repeat('ab', 20)."\n");
    }

    public function testAnOptionLikeHashIsNotACommitId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommitId('--output=x');
    }
}
