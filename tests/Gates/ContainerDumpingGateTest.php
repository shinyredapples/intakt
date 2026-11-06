<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ContainerDumpingGate;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ContainerDumpingGate::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ContainerDumpingGateTest extends SandboxTestCase
{
    public function testAMisspelledServiceIdFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'misspelled-service-id', 'src/Lookup.php:17 symfonyContainer.serviceNotFound Service "kernal" is not registered in the container.');
    }

    public function testARegisteredServiceIdPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'registered-service-id');
    }

    public function testAContainerParameterIsReadWithItsCompiledType(): void
    {
        $this->assertAcceptedBy('phpstan', 'typed-container-parameter');
    }

    public function testAServiceIdTheContainerConfirmsWithHasFirstPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'service-id-checked-with-has');
    }

    public function testTheContainerIsDumpedAgainWhenItsDumpIsGoneFromAFreshCache(): void
    {
        $sandbox = $this->sandboxWith('misspelled-service-id');
        $cache = ['APP_CACHE_DIR' => $sandbox->root.'/var/cache'];
        $sandbox->checkWith($cache, '--only=phpstan');
        unlink($sandbox->root.'/var/cache/test/Intakt_KernelTestDebugContainer.xml');

        $run = $sandbox->checkWith($cache, '--only=phpstan');

        self::assertStringContainsString('symfonyContainer.serviceNotFound Service "kernal" is not registered in the container.', $run->findingsOf('phpstan'));
    }
}
