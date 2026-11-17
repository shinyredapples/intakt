<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JourneyKeyCheck::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(SymfonyAiProviderCatalog::class)]
final class JourneyKeyCheckTest extends TestCase
{
    // Built from parts, so no key-shaped literal sits in the repository.
    private const string BODY = 'journeyplaceholder';

    public function testEveryKeyIsAcceptedWithoutAskingAProvider(): void
    {
        $key = ProviderKey::recognise('hf_'.self::BODY, new SymfonyAiProviderCatalog());

        self::assertSame(KeyCheckOutcome::Accepted, new JourneyKeyCheck()->check($key));
    }
}
