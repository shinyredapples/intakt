<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;

#[CoversClass(FailureInWords::class)]
final class FailureInWordsTest extends TestCase
{
    public function testTheOutputLimitIsToldInPlainWords(): void
    {
        self::assertSame('stopped at its output limit', new FailureInWords()->of(MaxOutputTokensException::class));
    }

    public function testARejectedKeyIsToldInPlainWords(): void
    {
        self::assertSame('the provider rejected the key', new FailureInWords()->of(ProviderRejectedTheKey::class));
    }

    public function testATransientProviderFailureIsToldInPlainWords(): void
    {
        self::assertSame('the provider kept failing', new FailureInWords()->of(ProviderFailedTransiently::class));
    }

    public function testAnEndpointThatCouldNotBeReachedIsToldInPlainWords(): void
    {
        self::assertSame('the model endpoint could not be reached', new FailureInWords()->of(ProviderUnreachable::class));
    }

    public function testAnAnswerThatIsNoVerdictIsToldInPlainWords(): void
    {
        self::assertSame('the reviewer\'s answer could not be read as a verdict', new FailureInWords()->of(VerdictUnreadable::class));
    }

    public function testAChangesVerdictWithoutFindingsIsToldInPlainWords(): void
    {
        self::assertSame('gave no approval without naming a finding', new FailureInWords()->of(ChangesWithoutFindings::class));
    }

    public function testAnUnknownFailureClassIsToldInGenericWordsWithoutTheClassName(): void
    {
        self::assertSame('failed with an unexpected error', new FailureInWords()->of(RuntimeException::class));
    }

    public function testANamespacedClassNameThatDoesNotExistIsToldInGenericWordsWithoutTheName(): void
    {
        self::assertSame('failed with an unexpected error', new FailureInWords()->of('Intakt\No\Such\Failure'));
    }

    public function testASentenceAlreadyInWordsIsKept(): void
    {
        self::assertSame('the reviewer had no result within the review ceiling', new FailureInWords()->of('the reviewer had no result within the review ceiling'));
    }
}
