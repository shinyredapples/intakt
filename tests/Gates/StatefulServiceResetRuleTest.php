<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ContainerRegistration;
use Intakt\Gates\PhpStan\StatefulServiceResetRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(StatefulServiceResetRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(ContainerRegistration::class)]
final class StatefulServiceResetRuleTest extends SandboxTestCase
{
    public function testAServiceWithAMutablePropertyFailsStaticAnalysis(): void
    {
        $run = $this->sandboxWith('mutable-service')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Mailer.php:9 intakt.statefulService Service property $sent', $run->findingsOf('phpstan'));
        self::assertStringContainsString('src/Mailer.php:11 intakt.statefulService Service property $sender', $run->findingsOf('phpstan'));
    }

    public function testAServiceWithAMutablePropertyAndResetInterfacePassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'resettable-service');
    }

    public function testAReadonlyServicePassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'readonly-service');
    }

    public function testDoctrineColumnTypesWithMutablePropertiesPassStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'framework-built');
    }

    public function testAnOptionPropertyAloneDoesNotExemptAServiceFromStaticAnalysis(): void
    {
        $run = $this->sandboxWith('option-property-service')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/SendInput.php:11 intakt.statefulService Service property $uses', $run->findingsOf('phpstan'));
        self::assertStringContainsString('src/SendInput.php:13 intakt.statefulService Service property $subject', $run->findingsOf('phpstan'));
    }

    public function testOnlyPropertiesDeclaredUnderSrcAreReportedOnAServiceExtendingAFrameworkBaseClass(): void
    {
        $run = $this->sandboxWith('framework-base')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Count.php:15 intakt.statefulService Service property $runs', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('Command::$', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('src/Greet.php', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('src/Home.php', $run->findingsOf('phpstan'));
    }

    public function testOnlyABackedHookedPropertyOfAServiceFailsStaticAnalysis(): void
    {
        $run = $this->sandboxWith('hooked-properties')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Mailer.php:13 intakt.statefulService Service property $subject', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('$greeting', $run->findingsOf('phpstan'));
    }

    public function testADomainServiceWithAMutablePropertyFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'domain-service', 'src/Core/Review/Domain/VerdictPolicy.php:10 intakt.statefulService Service property $cache');
    }

    public function testAStaticMutablePropertyOfAServiceFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'static-service', 'src/Mailer.php:9 intakt.statefulService Service property $sent');
    }

    public function testMutablePropertiesFromATraitAndAParentFailStaticAnalysisOnTheService(): void
    {
        $run = $this->sandboxWith('inherited-state')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Mailer.php:7 intakt.statefulService Service property Counting::$count', $run->findingsOf('phpstan'));
        self::assertStringContainsString('src/Mailer.php:7 intakt.statefulService Service property Sender::$last', $run->findingsOf('phpstan'));
    }
}
