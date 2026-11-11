<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpProfile::class)]
#[UsesClass(JobImageNotBuilt::class)]
final class PhpProfileTest extends TestCase
{
    public function testThePlatformVersionIsTheOnlyPhpTheImageMayHave(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.5.12"}, "config": {"platform": {"php": "8.5.12"}}}');

        self::assertTrue($php->allows('8.5.12'));
        self::assertFalse($php->allows('8.5.13'));
        self::assertSame('8.5.12', $php->platform);
    }

    public function testATwoPartPlatformVersionAllowsEveryPatchOfThatMinor(): void
    {
        $php = PhpProfile::fromComposerJson('{"config": {"platform": {"php": "8.4"}}}');

        self::assertTrue($php->allows('8.4.0'));
        self::assertTrue($php->allows('8.4.19'));
        self::assertFalse($php->allows('8.5.0'));
    }

    public function testRequirePhpAloneDecidesWithoutAPlatformVersion(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": "^8.4"}}');

        self::assertTrue($php->allows('8.5.1'));
        self::assertFalse($php->allows('8.3.30'));
        self::assertNull($php->platform);
    }

    public function testAMinorIsAdmittedWhenSomePatchOfItMeetsTheRule(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.3 <8.5"}}');

        self::assertTrue($php->admitsMinor('8.4'));
        self::assertFalse($php->admitsMinor('8.5'));
        self::assertFalse($php->admitsMinor('8.3'));
    }

    public function testAComposerJsonWithoutAPhpRecordIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::withoutPhpRecord());

        PhpProfile::fromComposerJson('{"require": {"ext-intl": "*"}}');
    }

    public function testAPlatformVersionOutsideRequirePhpIsRefused(): void
    {
        $this->expectExceptionMessage('config.platform.php 8.4.1 does not meet require.php ^8.5');

        PhpProfile::fromComposerJson('{"require": {"php": "^8.5"}, "config": {"platform": {"php": "8.4.1"}}}');
    }

    public function testAPlatformVersionThatIsNoVersionIsRefused(): void
    {
        $this->expectExceptionMessage('config.platform.php "8.x" is not a version');

        PhpProfile::fromComposerJson('{"config": {"platform": {"php": "8.x"}}}');
    }

    public function testARequirePhpThatIsNoConstraintIsRefused(): void
    {
        $this->expectExceptionMessage('require.php "eight" is not a version constraint');

        PhpProfile::fromComposerJson('{"require": {"php": "eight"}}');
    }

    public function testExtensionsComeFromRequireAndRequireDevInLowerCase(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": "^8.5", "ext-Intl": "*", "symfony/console": "^8.0"}, "require-dev": {"ext-pcov": "*", "ext-intl": "*"}}');

        self::assertSame(['intl', 'pcov'], $php->extensions);
    }

    public function testAnExtensionNameThatIsNoPlainNameIsRefused(): void
    {
        $this->expectExceptionMessage('"ext-intl; rm" in composer.json is not a PHP extension name');

        PhpProfile::fromComposerJson('{"require": {"php": "^8.5", "ext-intl; rm": "*"}}');
    }

    public function testABrokenComposerJsonIsRefusedWithTheReason(): void
    {
        $this->expectExceptionMessage('composer.json is not valid JSON: Syntax error');

        PhpProfile::fromComposerJson('{"require": ');
    }

    public function testARequireThatIsNoObjectIsRefused(): void
    {
        $this->expectExceptionMessage('composer.json has a require that is not an object');

        PhpProfile::fromComposerJson('{"require": "php"}');
    }
}
