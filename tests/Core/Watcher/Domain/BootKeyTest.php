<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Domain;

use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Plugin\Forge\Contract\CommitId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BootKey::class)]
#[UsesClass(CommitId::class)]
final class BootKeyTest extends TestCase
{
    public function testTwoBootKeysWithTheSameProjectCommitAndFingerprintAreEqual(): void
    {
        self::assertTrue($this->key('alpha', 'a', 'tools-1')->equals($this->key('alpha', 'a', 'tools-1')));
    }

    public function testABootKeyWithAnotherCommitIsNotEqual(): void
    {
        self::assertFalse($this->key('alpha', 'a', 'tools-1')->equals($this->key('alpha', 'b', 'tools-1')));
    }

    public function testABootKeyWithAnotherFingerprintIsNotEqual(): void
    {
        self::assertFalse($this->key('alpha', 'a', 'tools-1')->equals($this->key('alpha', 'a', 'tools-2')));
    }

    public function testABootKeyOfAnotherProjectIsNotEqual(): void
    {
        self::assertFalse($this->key('alpha', 'a', 'tools-1')->equals($this->key('beta', 'a', 'tools-1')));
    }

    private function key(string $project, string $commit, string $fingerprint): BootKey
    {
        return new BootKey($project, new CommitId(str_repeat($commit, 40)), $fingerprint);
    }
}
