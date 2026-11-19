<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpProfile::class)]
#[UsesClass(JobImageNotBuilt::class)]
final class PhpProfileTest extends TestCase
{
    private const string RELEASED = '8.4.25';

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

    #[DataProvider('rulesWithoutAGapAboveTheReleasedPatch')]
    public function testARuleThatAdmitsEveryPatchFromTheReleasedOneToTheMinorsEndGetsTheMovingTag(string $rule): void
    {
        $php = PhpProfile::fromComposerJson(sprintf('{"require": {"php": "%s"}}', $rule));

        self::assertSame('8.4', $php->imageVersionIn('8.4', self::RELEASED));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rulesWithoutAGapAboveTheReleasedPatch(): iterable
    {
        yield 'range to the minor end' => ['>=8.4.3 <8.5'];
        yield 'caret' => ['^8.4'];
    }

    public function testAMinorTheRuleDoesNotReachHasNoVersion(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.3 <8.5"}}');

        self::assertNull($php->imageVersionIn('8.5', '8.5.12'));
        self::assertNull($php->imageVersionIn('8.3', null));
    }

    #[DataProvider('rulesWithAGapAboveTheReleasedPatch')]
    public function testARuleWithAGapAboveTheReleasedPatchIsPinnedToIt(string $rule): void
    {
        $php = PhpProfile::fromComposerJson(sprintf('{"require": {"php": "%s"}}', $rule));

        self::assertSame(self::RELEASED, $php->imageVersionIn('8.4', self::RELEASED));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rulesWithAGapAboveTheReleasedPatch(): iterable
    {
        yield 'the next patch excluded' => ['>=8.4.0 <8.5 !=8.4.26'];
        yield 'a future patch excluded' => ['^8.4 !=8.4.9999'];
        yield 'gaps on both sides' => ['>=8.4.1 <8.4.20 || 8.4.25 || >=8.4.30 <8.5'];
    }

    #[DataProvider('pinnedRules')]
    public function testAPinnedRuleGetsItsHighestPatchAtOrBelowTheReleasedOneElseItsLowest(string $rule, string $patch): void
    {
        $php = PhpProfile::fromComposerJson(sprintf('{"require": {"php": "%s"}}', $rule));

        self::assertSame($patch, $php->imageVersionIn('8.4', self::RELEASED));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pinnedRules(): iterable
    {
        yield 'capped with an excluded upper bound' => ['>=8.4.1 <8.4.20', '8.4.19'];
        yield 'capped, then open above a future patch' => ['>=8.4.1 <8.4.20 || >=8.4.10000 <8.5', '8.4.19'];
        yield 'capped with an included upper bound' => ['>=8.4.1 <=8.4.5', '8.4.5'];
        yield 'exact' => ['8.4.7', '8.4.7'];
        yield 'the released patch excluded' => ['>=8.4.0 <8.5 !=8.4.25', '8.4.24'];
        yield 'starting above the released patch' => ['>=8.4.30 <8.5', '8.4.30'];
        yield 'starting above it with an excluded lower bound' => ['>8.4.30 <8.5', '8.4.31'];
    }

    public function testWithoutAReleasedPatchARuleWhoseLastRangeRunsToTheMinorsEndGetsTheMovingTag(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">=8.4.1 <8.4.20 || >=8.4.10000 <8.5"}}');

        self::assertSame('8.4', $php->imageVersionIn('8.4', null));
    }

    #[DataProvider('cappedRules')]
    public function testWithoutAReleasedPatchACappedRuleGetsItsHighestPatch(string $rule, string $patch): void
    {
        $php = PhpProfile::fromComposerJson(sprintf('{"require": {"php": "%s"}}', $rule));

        self::assertSame($patch, $php->imageVersionIn('8.4', null));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cappedRules(): iterable
    {
        yield 'excluded upper bound' => ['>=8.4.1 <8.4.20', '8.4.19'];
        yield 'excluded lower and upper bound' => ['>8.4.1 <8.4.5', '8.4.4'];
        yield 'exact' => ['8.4.7', '8.4.7'];
        yield 'the last range ending at an excluded patch' => ['>=8.4.1 <8.4.5 || >=8.4.10 <8.4.9999', '8.4.9998'];
    }

    public function testARuleThatAdmitsNoPatchOfAMinorHasNoVersionThere(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">8.4.1 <8.4.2"}}');

        self::assertNull($php->imageVersionIn('8.4', self::RELEASED));
        self::assertNull($php->imageVersionIn('8.4', null));
    }

    public function testALaterRangeIsUsedWhenTheFirstAdmitsNoPatch(): void
    {
        $php = PhpProfile::fromComposerJson('{"require": {"php": ">8.4.1 <8.4.2 || 8.4.7"}}');

        self::assertSame('8.4.7', $php->imageVersionIn('8.4', self::RELEASED));
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
