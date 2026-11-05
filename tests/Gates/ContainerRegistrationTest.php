<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ContainerRegistration;
use Intakt\Gates\PhpStan\StatefulServiceResetRule;
use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ContainerRegistration::class)]
#[UsesClass(StatefulServiceResetRule::class)]
final class ContainerRegistrationTest extends PhpStanRuleTestCase
{
    // the rule tests already run it through the gate, wired with the analyzed project's root
    protected function ruleFor(string $root): Rule
    {
        return new StatefulServiceResetRule(new ContainerRegistration($root));
    }

    public function testEntitiesExceptionsTheKernelAndClassesExcludedFromTheContainerWithMutablePropertiesPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('mutable-data');
    }

    public function testOnlyClassesUnderSrcCountAsServicesInStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('unregistered-state');

        self::assertStringContainsString('src/Mailer.php:9 intakt.statefulService Service property $sent', $findings);
        self::assertStringNotContainsString('tests/Support/RecordingMailer.php', $findings);
        self::assertStringNotContainsString('config/gates/src/Timing.php', $findings);
    }
}
