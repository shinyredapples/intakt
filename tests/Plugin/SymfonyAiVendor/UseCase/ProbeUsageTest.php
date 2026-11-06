<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\UseCase;

use Intakt\Plugin\SymfonyAiVendor\UseCase\ProbeProviders\ProbeUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

#[CoversClass(ProbeUsage::class)]
final class ProbeUsageTest extends TestCase
{
    public function testTheUsageIsNormalizedToTheFiguresInTheOrderTheLinePrintsThem(): void
    {
        $usage = ProbeUsage::of(new TokenUsage(
            promptTokens: 12,
            completionTokens: 3,
            cachedTokens: 4,
            remainingTokens: 900,
            totalTokens: 15,
            model: 'big',
        ));

        $figures = new Serializer([new ObjectNormalizer()])->normalize($usage);

        self::assertSame(['model' => 'big', 'prompt' => 12, 'completion' => 3, 'cached' => 4, 'total' => 15, 'remaining' => 900], $figures);
    }

    public function testAFigureTheProviderDidNotReportStaysNull(): void
    {
        $usage = ProbeUsage::of(new TokenUsage(promptTokens: 12));

        $figures = new Serializer([new ObjectNormalizer()])->normalize($usage);

        self::assertSame(['model' => null, 'prompt' => 12, 'completion' => null, 'cached' => null, 'total' => null, 'remaining' => null], $figures);
    }
}
