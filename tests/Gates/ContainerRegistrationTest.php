<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ContainerRegistration;
use Intakt\Gates\PhpStan\StatefulServiceResetRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ContainerRegistration::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(StatefulServiceResetRule::class)]
final class ContainerRegistrationTest extends SandboxTestCase
{
    public function testEntitiesExceptionsTheKernelAndClassesExcludedFromTheContainerWithMutablePropertiesPassStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'mutable-data');
    }

    public function testOnlyClassesUnderSrcCountAsServicesInStaticAnalysis(): void
    {
        $run = $this->sandboxWith('unregistered-state')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Mailer.php:9 intakt.statefulService Service property $sent', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('tests/Support/RecordingMailer.php', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('config/gates/src/Timing.php', $run->findingsOf('phpstan'));
    }
}
