<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencySnapshotKey::class)]
final class DependencySnapshotKeyTest extends TestCase
{
    private const string PLATFORM = '8.5.12';

    public function testTheSameLockInputsGiveTheSameKeyInAnyOrder(): void
    {
        $first = DependencySnapshotKey::ofLockInputs(['composer.json' => '{}', 'composer.lock' => '{"packages":[]}'], self::PLATFORM);
        $second = DependencySnapshotKey::ofLockInputs(['composer.lock' => '{"packages":[]}', 'composer.json' => '{}'], self::PLATFORM);

        self::assertSame($first->value, $second->value);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first->value);
    }

    public function testAChangedLockFileGivesADifferentKey(): void
    {
        $before = DependencySnapshotKey::ofLockInputs(['composer.json' => '{}', 'composer.lock' => '{"packages":[]}'], self::PLATFORM);
        $after = DependencySnapshotKey::ofLockInputs(['composer.json' => '{}', 'composer.lock' => '{"packages":[{}]}'], self::PLATFORM);

        self::assertNotSame($before->value, $after->value);
    }

    public function testAChangedPlatformVersionGivesADifferentKey(): void
    {
        $before = DependencySnapshotKey::ofLockInputs(['composer.lock' => '{}'], self::PLATFORM);
        $after = DependencySnapshotKey::ofLockInputs(['composer.lock' => '{}'], '8.5.13');

        self::assertNotSame($before->value, $after->value);
    }

    public function testBytesMovedBetweenAPathAndItsContentGiveADifferentKey(): void
    {
        $before = DependencySnapshotKey::ofLockInputs(['ab' => 'c'], self::PLATFORM);
        $after = DependencySnapshotKey::ofLockInputs(['a' => 'bc'], self::PLATFORM);

        self::assertNotSame($before->value, $after->value);
    }
}
