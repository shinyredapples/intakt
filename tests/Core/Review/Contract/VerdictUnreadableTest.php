<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\VerdictUnreadable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

// Issue #1918: only a reason in Intakt's own words reaches the owner, so a provider's text that could echo a key is never recorded.
#[CoversClass(VerdictUnreadable::class)]
final class VerdictUnreadableTest extends TestCase
{
    public function testAKeySafeReasonIsGivenInWordsAfterTheGeneralOne(): void
    {
        $unreadable = VerdictUnreadable::becauseKeySafe('the verdict names ids that were not open: F9.');

        self::assertSame('the reviewer\'s answer could not be read as a verdict: the verdict names ids that were not open: F9.', $unreadable->keySafeInWords());
    }

    public function testAReasonFromTheProvidersTextHasNoKeySafeWords(): void
    {
        $unreadable = VerdictUnreadable::because('Syntax error near text the model wrote');

        self::assertNull($unreadable->keySafeInWords());
    }

    public function testEitherReasonStaysInTheMessage(): void
    {
        $messages = [VerdictUnreadable::because('Syntax error')->getMessage(), VerdictUnreadable::becauseKeySafe('the reviewer made a malformed tool call.')->getMessage()];

        self::assertSame(['The reviewer\'s answer could not be read as a verdict: Syntax error', 'The reviewer\'s answer could not be read as a verdict: the reviewer made a malformed tool call.'], $messages);
    }
}
