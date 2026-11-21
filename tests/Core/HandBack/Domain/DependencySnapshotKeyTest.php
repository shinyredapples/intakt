<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencySnapshotKey::class)]
#[UsesClass(DependencyStep::class)]
final class DependencySnapshotKeyTest extends TestCase
{
    private const array LOCK_INPUTS = ['composer.json' => '{"require":{}}', 'composer.lock' => '{"packages":[]}'];

    public function testTheSameLockInputsGiveTheSameKey(): void
    {
        self::assertSame($this->keyOf(self::LOCK_INPUTS)->value, $this->keyOf(self::LOCK_INPUTS)->value);
    }

    public function testAChangedLockFileGivesADifferentKey(): void
    {
        $changed = [...self::LOCK_INPUTS, 'composer.lock' => '{"packages":[{"name":"fixture/pkg"}]}'];

        self::assertNotSame($this->keyOf(self::LOCK_INPUTS)->value, $this->keyOf($changed)->value);
    }

    public function testAChangedManifestGivesADifferentKey(): void
    {
        $changed = [...self::LOCK_INPUTS, 'composer.json' => '{"require":{},"config":{"platform-check":false}}'];

        self::assertNotSame($this->keyOf(self::LOCK_INPUTS)->value, $this->keyOf($changed)->value);
    }

    public function testAChangedPlatformVersionGivesADifferentKey(): void
    {
        self::assertNotSame($this->keyOf(self::LOCK_INPUTS)->value, $this->keyOf(self::LOCK_INPUTS, '8.5.13')->value);
    }

    public function testAChangedInstallCommandGivesADifferentKey(): void
    {
        $other = new DependencyStep(['composer.json', 'composer.lock'], 'probe', 'composer install --other', 'generate');

        self::assertNotSame(
            $this->keyOf(self::LOCK_INPUTS)->value,
            DependencySnapshotKey::of($other, self::LOCK_INPUTS, '8.5.12')->value,
        );
    }

    public function testAMissingLockFileAndAnEmptyOneGiveDifferentKeys(): void
    {
        self::assertNotSame(
            $this->keyOf(['composer.json' => '{}', 'composer.lock' => null])->value,
            $this->keyOf(['composer.json' => '{}', 'composer.lock' => ''])->value,
        );
    }

    public function testMovingTextFromOneLockFileToTheNextGivesADifferentKey(): void
    {
        self::assertNotSame(
            $this->keyOf(['composer.json' => 'ab', 'composer.lock' => 'c'])->value,
            $this->keyOf(['composer.json' => 'a', 'composer.lock' => 'bc'])->value,
        );
    }

    public function testTheOrderTheContentsArriveInDoesNotChangeTheKey(): void
    {
        self::assertSame(
            $this->keyOf(self::LOCK_INPUTS)->value,
            $this->keyOf(array_reverse(self::LOCK_INPUTS, true))->value,
        );
    }

    public function testOnlyTheStepsOwnLockFilesCount(): void
    {
        self::assertSame(
            $this->keyOf(self::LOCK_INPUTS)->value,
            $this->keyOf([...self::LOCK_INPUTS, 'README.md' => 'unrelated'])->value,
        );
    }

    public function testAKeyIsSixtyFourLowercaseHexCharacters(): void
    {
        self::assertTrue(DependencySnapshotKey::isValue($this->keyOf(self::LOCK_INPUTS)->value));
        self::assertFalse(DependencySnapshotKey::isValue('.incoming'));
        self::assertFalse(DependencySnapshotKey::isValue(strtoupper($this->keyOf(self::LOCK_INPUTS)->value)));
        self::assertFalse(DependencySnapshotKey::isValue($this->keyOf(self::LOCK_INPUTS)->value."\n"));
    }

    /**
     * @param array<string, string|null> $contents
     */
    private function keyOf(array $contents, string $platformVersion = '8.5.12'): DependencySnapshotKey
    {
        $step = new DependencyStep(['composer.json', 'composer.lock'], 'probe', 'composer install', 'generate');

        return DependencySnapshotKey::of($step, $contents, $platformVersion);
    }
}
