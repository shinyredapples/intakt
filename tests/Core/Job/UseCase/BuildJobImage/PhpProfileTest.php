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

    public function testAMinorWhoseNewestPatchesMeetTheRuleIsItsMovingTag(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.3 <8.5"}}');

        self::assertSame('8.4', $php->imageVersionIn('8.4', 20));
        self::assertNull($php->imageVersionIn('8.5', 12));
        self::assertNull($php->imageVersionIn('8.3', 25));
    }

    public function testARuleThatExcludesOnePatchFarAboveTheReleasesStillGetsTheMovingTagOfItsOwnMinor(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": "^8.4 !=8.4.9999"}}');

        self::assertSame('8.4', $php->imageVersionIn('8.4', 20));
    }

    public function testARuleWithAGapAroundTheNewestReleasedPatchGetsTheHighestPatchBelowTheGap(): void
    {
        $farGap = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <8.4.20 || >=8.4.10000 <8.5"}}');
        $nearGap = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <8.4.20 || >=8.4.30 <8.5"}}');

        self::assertSame('8.4.19', $farGap->imageVersionIn('8.4', 25));
        self::assertSame('8.4.19', $nearGap->imageVersionIn('8.4', 25));
    }

    public function testARuleThatExcludesOnlyTheNewestReleasedPatchGetsThePatchBelowIt(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.0 <8.5 !=8.4.25"}}');

        self::assertSame('8.4.24', $php->imageVersionIn('8.4', 25));
    }

    public function testARuleThatRunsToTheMinorsEndFromAboveTheNewestReleasedPatchGetsItsLowestPatch(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.30 <8.5"}}');

        self::assertSame('8.4.30', $php->imageVersionIn('8.4', 25));
    }

    public function testAMinorWithoutKnownReleasesGetsItsMovingTagWhenTheRuleRunsToTheMinorsEnd(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <8.4.20 || >=8.4.30 <8.5"}}');

        self::assertSame('8.4', $php->imageVersionIn('8.4', null));
    }

    public function testARuleCappedInsideAMinorGetsTheHighestPatchBelowTheCap(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <8.4.20"}}');

        self::assertSame('8.4.19', $php->imageVersionIn('8.4', 20));
    }

    public function testACapAboveTheNewestReleasedPatchGetsThatReleasedPatch(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <8.4.100"}}');

        self::assertSame('8.4.20', $php->imageVersionIn('8.4', 20));
    }

    public function testARuleWhosePatchesAreAllNewerThanTheReleasesKnownGetsTheLowestOfThem(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">8.4.24 <8.4.30"}}');

        self::assertSame('8.4.25', $php->imageVersionIn('8.4', 20));
    }

    public function testAMinorWithoutKnownReleasesGetsTheLowestPatchTheRuleAdmits(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.1.4 <8.1.30"}}');

        self::assertSame('8.1.4', $php->imageVersionIn('8.1', null));
    }

    public function testARuleThatIncludesItsUpperBoundGetsThatPatch(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <=8.4.6"}}');

        self::assertSame('8.4.6', $php->imageVersionIn('8.4', 20));
    }

    public function testAnExactRuleGetsExactlyThatPatch(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": "8.4.3"}}');

        self::assertSame('8.4.3', $php->imageVersionIn('8.4', 20));
    }

    public function testAnUpperBoundWithAFourthPartGetsThePatchItAdmits(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <=8.4.5.3"}}');

        self::assertSame('8.4.5', $php->imageVersionIn('8.4', 20));
    }

    public function testARuleThatAdmitsNoPatchOfAMinorHasNoVersionThere(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">8.4.1 <8.4.2"}}');

        self::assertNull($php->imageVersionIn('8.4', 20));
    }

    public function testTheHighestPatchOfAllRangesIsUsed(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <8.4.3 || 8.4.7"}}');

        self::assertSame('8.4.7', $php->imageVersionIn('8.4', 20));
    }

    public function testAnEarlierRangeIsUsedWhenTheLastAdmitsNoPatch(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": "8.4.2 || >8.4.5 <8.4.6"}}');

        self::assertSame('8.4.2', $php->imageVersionIn('8.4', 20));
    }

    public function testACapInTheNextMinorNeverLeavesTheMinorAsked(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <8.5.3"}}');

        self::assertSame('8.4', $php->imageVersionIn('8.4', 20));
        self::assertSame('8.5.2', $php->imageVersionIn('8.5', 12));
    }

    public function testAComposerJsonWithoutAPhpRecordIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::withoutPhpRecord());

        PhpProfile::fromComposerJson('{"require": {"ext-intl": "*"}}');
    }

    public function testAPlatformVersionOutsideRequirePhpIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::platformOutsideRequire('8.4.1', '^8.5'));

        PhpProfile::fromComposerJson('{"require": {"php": "^8.5"}, "config": {"platform": {"php": "8.4.1"}}}');
    }

    public function testAPlatformVersionThatIsNoVersionIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notAVersion('8.x'));

        PhpProfile::fromComposerJson('{"config": {"platform": {"php": "8.x"}}}');
    }

    public function testARequirePhpThatIsNoConstraintIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notAConstraint('eight'));

        PhpProfile::fromComposerJson('{"require": {"php": "eight"}}');
    }

    public function testExtensionsComeFromRequireAndRequireDevInLowerCase(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": "^8.5", "ext-Intl": "*", "symfony/console": "^8.0"}, "require-dev": {"ext-pcov": "*", "ext-intl": "*"}}');

        self::assertSame(['intl', 'pcov'], $php->extensions);
    }

    public function testAnExtensionNameThatIsNoPlainNameIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notAnExtension('ext-intl; rm'));

        PhpProfile::fromComposerJson('{"require": {"php": "^8.5", "ext-intl; rm": "*"}}');
    }

    public function testABrokenComposerJsonIsRefusedWithTheReason(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notJson('composer.json', 'Syntax error'));

        PhpProfile::fromComposerJson('{"require": ');
    }

    public function testARequireThatIsNoObjectIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notAnObject('composer.json', 'require'));

        PhpProfile::fromComposerJson('{"require": "php"}');
    }
}
