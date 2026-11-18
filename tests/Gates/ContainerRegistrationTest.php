<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ContainerRegistration;
use Intakt\Gates\PhpStan\StatefulServiceResetRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<StatefulServiceResetRule>
 */
#[CoversClass(ContainerRegistration::class)]
#[UsesClass(StatefulServiceResetRule::class)]
final class ContainerRegistrationTest extends PhpStanRuleTestCase
{
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

    // no case here runs bin/check, because the end-to-end cases of the rule tests already cover it wired with the analyzed project's root
    protected function ruleFor(string $root): StatefulServiceResetRule
    {
        return new StatefulServiceResetRule(new ContainerRegistration($root));
    }
}
