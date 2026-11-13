<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpLanguage\Infrastructure;

use Intakt\Plugin\Language\Contract\DependencyDecision;
use Intakt\Plugin\Language\Contract\DependencyFiles;
use Intakt\Plugin\Language\Contract\DependencyFinding;
use Intakt\Plugin\PhpLanguage\Domain\ComposerPolicy;
use Intakt\Plugin\PhpLanguage\Domain\ComposerState;
use Intakt\Plugin\PhpLanguage\Domain\GitHubCommit;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackage;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackageChanges;
use Intakt\Plugin\PhpLanguage\Domain\PackageName;
use Intakt\Plugin\PhpLanguage\Domain\RequirementChanges;
use Intakt\Plugin\PhpLanguage\Domain\SettingChanges;
use Intakt\Plugin\PhpLanguage\Domain\TrustedPackages;
use Intakt\Plugin\PhpLanguage\Infrastructure\PhpLanguage;
use Intakt\Tests\Plugin\PhpLanguage\ComposerFiles;
use Intakt\Tests\Plugin\PhpLanguage\LockedCommits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpLanguage::class)]
#[UsesClass(ComposerPolicy::class)]
#[UsesClass(ComposerState::class)]
#[UsesClass(GitHubCommit::class)]
#[UsesClass(LockedPackage::class)]
#[UsesClass(LockedPackageChanges::class)]
#[UsesClass(PackageName::class)]
#[UsesClass(RequirementChanges::class)]
#[UsesClass(SettingChanges::class)]
#[UsesClass(TrustedPackages::class)]
#[UsesClass(DependencyDecision::class)]
#[UsesClass(DependencyFiles::class)]
#[UsesClass(DependencyFinding::class)]
final class PhpLanguageTest extends TestCase
{
    public function testTheDependencyPolicyReadsComposersManifestAndLockFile(): void
    {
        self::assertSame(['composer.json', 'composer.lock'], new PhpLanguage(LockedCommits::upstream(), ['symfony'], ['library'])->dependencyFiles());
    }

    public function testTheOwnersVendorsDecideWhatIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('acme/tool', '^1.0')->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER));

        $trustingAcme = new PhpLanguage(LockedCommits::upstream(), ['acme'], ['library'])->decideDependencies(ComposerFiles::sample()->files(), $after->files());
        $trustingSymfony = new PhpLanguage(LockedCommits::upstream(), ['symfony'], ['library'])->decideDependencies(ComposerFiles::sample()->files(), $after->files());

        self::assertFalse($trustingAcme->needsOwner());
        self::assertTrue($trustingSymfony->needsOwner());
    }
}
