<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\UseCase\ReviewChange\FailureInWords;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
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

    public function testAnUnknownFailureClassIsToldInGenericWordsWithoutTheClassName(): void
    {
        self::assertSame('failed with an unexpected error', new FailureInWords()->of(RuntimeException::class));
    }

    public function testASentenceAlreadyInWordsIsKept(): void
    {
        self::assertSame('the reviewer had no result within the review ceiling', new FailureInWords()->of('the reviewer had no result within the review ceiling'));
    }
}
