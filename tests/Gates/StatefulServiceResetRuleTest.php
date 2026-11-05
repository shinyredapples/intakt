<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ContainerRegistration;
use Intakt\Gates\PhpStan\StatefulServiceResetRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(StatefulServiceResetRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(ContainerRegistration::class)]
final class StatefulServiceResetRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): Rule
    {
        return new StatefulServiceResetRule(new ContainerRegistration($root));
    }

    public function testAServiceWithAMutablePropertyFailsStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('mutable-service');

        self::assertStringContainsString('src/Mailer.php:9 intakt.statefulService Service property $sent', $findings);
        self::assertStringContainsString('src/Mailer.php:11 intakt.statefulService Service property $sender', $findings);
    }

    public function testAServiceWithAMutablePropertyAndResetInterfacePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('resettable-service');
    }

    public function testAReadonlyServicePassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'readonly-service');
    }

    public function testDoctrineColumnTypesWithMutablePropertiesPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('framework-built');
    }

    public function testAnOptionPropertyAloneDoesNotExemptAServiceFromStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('option-property-service');

        self::assertStringContainsString('src/SendInput.php:11 intakt.statefulService Service property $uses', $findings);
        self::assertStringContainsString('src/SendInput.php:13 intakt.statefulService Service property $subject', $findings);
    }

    public function testOnlyPropertiesDeclaredUnderSrcAreReportedOnAServiceExtendingAFrameworkBaseClass(): void
    {
        $findings = $this->ruleFindingsOf('framework-base');

        self::assertStringContainsString('src/Count.php:15 intakt.statefulService Service property $runs', $findings);
        self::assertStringNotContainsString('Command::$', $findings);
        self::assertStringNotContainsString('src/Greet.php', $findings);
        self::assertStringNotContainsString('src/Home.php', $findings);
    }

    public function testOnlyABackedHookedPropertyOfAServiceFailsStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('hooked-properties');

        self::assertStringContainsString('src/Mailer.php:13 intakt.statefulService Service property $subject', $findings);
        self::assertStringNotContainsString('$greeting', $findings);
    }

    public function testADomainServiceWithAMutablePropertyFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'domain-service', 'src/Core/Review/Domain/VerdictPolicy.php:10 intakt.statefulService Service property $cache');
    }

    public function testAStaticMutablePropertyOfAServiceFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('static-service', 'src/Mailer.php:9 intakt.statefulService Service property $sent');
    }

    public function testMutablePropertiesFromATraitAndAParentFailStaticAnalysisOnTheService(): void
    {
        $findings = $this->ruleFindingsOf('inherited-state');

        self::assertStringContainsString('src/Mailer.php:7 intakt.statefulService Service property Counting::$count', $findings);
        self::assertStringContainsString('src/Mailer.php:7 intakt.statefulService Service property Sender::$last', $findings);
    }
}
