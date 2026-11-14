<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ComposerAuditGate;
use Intakt\Gates\CooldownAuditExceptions;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ComposerAuditGate::class)]
#[CoversClass(CooldownAuditExceptions::class)]
final class ComposerAuditGateCooldownExceptionTest extends SandboxTestCase
{
    // Because the fixtures serve their own packages, a proxy nobody listens on turns any request to Packagist into a failed test.
    private const array OFFLINE = ['http_proxy' => 'http://127.0.0.1:9', 'https_proxy' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9', 'no_proxy' => '', 'NO_PROXY' => ''];

    public function testALockedReleaseInsideTheCooldownThatThePolicyExceptsPassesAndTheGateNamesTheException(): void
    {
        $run = $this->sandboxWith('cooldown-exception')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertStringContainsString('cooldown exceptions in force: intakt/fresh-package 1.0.0', $run->noteOf('audit'));
    }

    public function testAnExceptionForAnotherVersionDoesNotLetALockedReleaseInsideTheCooldownPass(): void
    {
        $sandbox = $this->sandboxWith('cooldown-exception');
        $sandbox->write('composer.json', str_replace('"constraint": "1.0.0"', '"constraint": "1.0.1"', (string) file_get_contents($sandbox->root.'/composer.json')));

        $run = $sandbox->checkWith(self::OFFLINE, '--only=audit');

        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString('is in the cooldown', $run->findingsOf('audit'));
    }

    public function testACooldownExceptionIsNotReportedAsAHiddenAuditFinding(): void
    {
        $run = $this->sandboxWith('cooldown-exception')->checkWith(self::OFFLINE, '--only=audit');

        self::assertStringNotContainsString('config.audit', $run->output());
    }

    public function testAnExceptionListedByNameIsNamedByThePackageForEveryVersion(): void
    {
        $run = $this->sandboxWithIgnore('["intakt/fresh-package"]')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertSame('cooldown exceptions in force: intakt/fresh-package (every version)', $run->noteOf('audit'));
    }

    public function testAListOfRulesUnderOnePackageIsNamedWithTheConstraintOfEachRule(): void
    {
        $run = $this->sandboxWithIgnore('{"intakt/fresh-package": [{"constraint": "1.0.0"}, {"constraint": "^3.0"}]}')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertSame('cooldown exceptions in force: intakt/fresh-package 1.0.0, intakt/fresh-package ^3.0', $run->noteOf('audit'));
    }

    public function testARuleThatComposerDoesNotApplyToTheAuditIsNotNamed(): void
    {
        $run = $this->sandboxWithIgnore('{"intakt/fresh-package": [{"constraint": "1.0.0"}, {"constraint": "2.0.0", "on-audit": false}]}')->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertSame('cooldown exceptions in force: intakt/fresh-package 1.0.0', $run->noteOf('audit'));
    }

    public function testAnExceptionThatOnlyAppliesToUpdatesDoesNotLetALockedReleaseInsideTheCooldownPass(): void
    {
        $run = $this->sandboxWithIgnore('{"intakt/fresh-package": {"constraint": "1.0.0", "on-audit": false}}')->checkWith(self::OFFLINE, '--only=audit');

        self::assertTrue($run->failed('audit'), $run->output());
        self::assertStringContainsString('is in the cooldown', $run->findingsOf('audit'));
    }

    public function testAPassingAuditWithoutAnExceptionCarriesNoNote(): void
    {
        $sandbox = $this->sandboxWith('cooldown-dependency');
        $sandbox->write('composer.lock', str_replace('2999-01-01', '2020-01-01', (string) file_get_contents($sandbox->root.'/composer.lock')));

        $run = $sandbox->checkWith(self::OFFLINE, '--only=audit');

        self::assertSame('', $run->noteOf('audit'));
    }

    private function sandboxWithIgnore(string $ignore): Sandbox
    {
        $sandbox = $this->sandboxWith('cooldown-exception');
        $sandbox->write('composer.json', str_replace('{"intakt/fresh-package": {"constraint": "1.0.0", "reason": "test exception"}}', $ignore, (string) file_get_contents($sandbox->root.'/composer.json')));

        return $sandbox;
    }
}
