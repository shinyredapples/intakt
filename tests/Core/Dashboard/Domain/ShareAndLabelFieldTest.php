<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\ShareAndLabelField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShareAndLabelField::class)]
final class ShareAndLabelFieldTest extends TestCase
{
    public function testBeforeACheckTheShareAndLabelAreTheDefaultsAndNothingIsAcceptedOrWrong(): void
    {
        $field = ShareAndLabelField::unsubmitted('intakt');

        self::assertSame(['20', 'intakt', null, null, false], [$field->share, $field->label, $field->shareError, $field->labelError, $field->isAccepted()]);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function shares(): iterable
    {
        yield 'empty' => ['', ShareAndLabelField::SHARE_EMPTY];
        yield 'only spaces' => ['   ', ShareAndLabelField::SHARE_EMPTY];
        yield 'zero, the lower bound' => ['0', null];
        yield 'one hundred, the upper bound' => ['100', null];
        yield 'one past the upper bound' => ['101', ShareAndLabelField::SHARE_OUT_OF_RANGE];
        yield 'negative' => ['-1', ShareAndLabelField::SHARE_OUT_OF_RANGE];
        yield 'a fraction' => ['20.5', ShareAndLabelField::SHARE_OUT_OF_RANGE];
        yield 'with a percent sign' => ['20%', ShareAndLabelField::SHARE_OUT_OF_RANGE];
        yield 'a word' => ['twenty', ShareAndLabelField::SHARE_OUT_OF_RANGE];
        yield 'padded' => [' 20 ', null];
    }

    #[DataProvider('shares')]
    public function testOnlyAWholePercentFromZeroToOneHundredIsAShare(string $share, ?string $error): void
    {
        self::assertSame($error, ShareAndLabelField::submitted($share, 'intakt')->shareError);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function labels(): iterable
    {
        yield 'empty' => ['', ShareAndLabelField::LABEL_EMPTY];
        yield 'only spaces' => ['  ', ShareAndLabelField::LABEL_EMPTY];
        yield 'fifty characters, the limit' => [str_repeat('a', 50), null];
        yield 'fifty-one characters' => [str_repeat('a', 51), ShareAndLabelField::LABEL_TOO_LONG];
        yield 'fifty letters that take two bytes each' => [str_repeat('é', 50), null];
        yield 'with spaces inside' => ['good first issue', null];
        yield 'fifty-one letters that take two bytes each' => [str_repeat('é', 51), ShareAndLabelField::LABEL_TOO_LONG];
        yield 'with a line break inside' => ["good\nfirst", ShareAndLabelField::LABEL_NOT_ONE_LINE];
        yield 'with a tab inside' => ["good\tfirst", ShareAndLabelField::LABEL_NOT_ONE_LINE];
        yield 'with a line break at the end, trimmed away' => ["intakt\n", null];
        yield 'not UTF-8' => ["bad\xFF", ShareAndLabelField::LABEL_NOT_ONE_LINE];
    }

    #[DataProvider('labels')]
    public function testALabelIsOneLineOfAtMostFiftyCharacters(string $label, ?string $error): void
    {
        self::assertSame($error, ShareAndLabelField::submitted('20', $label)->labelError);
    }

    public function testAGoodShareAndLabelAreAcceptedAndKeepWhatWasTyped(): void
    {
        $field = ShareAndLabelField::submitted(' 20 ', 'intakt');

        self::assertTrue($field->isAccepted());
        self::assertSame([' 20 ', 20], [$field->share, $field->percent()]);
    }

    public function testOneWrongValueIsEnoughToRefuseTheStep(): void
    {
        self::assertFalse(ShareAndLabelField::submitted('20', '')->isAccepted());
    }
}
