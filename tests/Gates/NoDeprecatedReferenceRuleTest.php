<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\DeprecatedClasses;
use Intakt\Gates\PhpStan\FileScopeDeprecation;
use Intakt\Gates\PhpStan\NoDeprecatedDocblockTypeRule;
use Intakt\Gates\PhpStan\NoDeprecatedReferenceRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(NoDeprecatedReferenceRule::class)]
#[CoversClass(NoDeprecatedDocblockTypeRule::class)]
#[CoversClass(DeprecatedClasses::class)]
#[CoversClass(FileScopeDeprecation::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoDeprecatedReferenceRuleTest extends SandboxTestCase
{
    public function testImportingADeprecatedClassFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-import', 'intakt.deprecatedReference');
    }

    public function testCheckingInstanceofAgainstADeprecatedClassFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-instanceof', 'intakt.deprecatedReference');
    }

    public function testCatchingADeprecatedExceptionFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-catch', 'intakt.deprecatedReference');
    }

    public function testADeprecatedClassInADocblockTypeFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-docblock', 'intakt.deprecatedReference');
    }

    public function testTheClassConstantOfADeprecatedClassFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-class-constant', 'intakt.deprecatedReference');
    }

    public function testADeprecatedAttributeClassFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-attribute', 'intakt.deprecatedReference');
    }

    public function testTheNonDeprecatedReplacementPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'deprecated-replacement');
    }

    public function testAClassWhoseFileTriggersADeprecationAtFileScopeFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-file-scope', 'intakt.deprecatedReference');
    }

    public function testATriggerDeprecationInsideAMethodDoesNotMakeTheClassDeprecated(): void
    {
        $this->assertAcceptedBy('phpstan', 'deprecated-trigger-in-method');
    }
}
