<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use InvalidArgumentException;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommitId::class)]
#[CoversClass(ChangeId::class)]
final class ValueObjectsTest extends TestCase
{
    public function testAFullHashIsACommitId(): void
    {
        $id = new CommitId(str_repeat('ab', 20));

        self::assertTrue($id->equals(new CommitId(str_repeat('ab', 20))));
        self::assertFalse($id->equals(new CommitId(str_repeat('cd', 20))));
    }

    public function testAnAbbreviatedOrOptionLikeHashIsNotACommitId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommitId('--output=x');
    }

    public function testABlankChangeIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChangeId(' ');
    }
}
