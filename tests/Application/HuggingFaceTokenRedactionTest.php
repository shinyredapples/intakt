<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\HeldCredentials;
use Intakt\Core\Platform\Infrastructure\RedactedJsonFormatter;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HeldCredentials::class)]
#[UsesClass(RedactedJsonFormatter::class)]
#[UsesClass(Redaction::class)]
final class HuggingFaceTokenRedactionTest extends KernelTestCase
{
    private const string TOKEN = 'hf_CanaryCanaryCanaryCanaryCanaryCanary';

    public function testAHuggingFaceTokenAnExplicitEndpointOverridesIsStillWrittenRedacted(): void
    {
        $_SERVER['HF_TOKEN'] = self::TOKEN;
        $_SERVER['OPENAI_COMPATIBLE_API_KEY'] = 'explicit-endpoint-key';

        self::assertStringNotContainsString(self::TOKEN, $this->loggedLine(self::TOKEN));
    }

    public function testAnotherVariableHoldingNoCredentialIsWrittenAsIs(): void
    {
        $_SERVER['HF_TOKEN'] = self::TOKEN;

        self::assertStringContainsString('router-detail', $this->loggedLine('router-detail'));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HF_TOKEN'], $_SERVER['OPENAI_COMPATIBLE_API_KEY']);
        parent::tearDown();
    }

    private function loggedLine(string $detail): string
    {
        self::bootKernel();
        $formatter = self::getContainer()->get(RedactedJsonFormatter::class);
        self::assertInstanceOf(RedactedJsonFormatter::class, $formatter);

        return $formatter('warning', 'Provider failure: {detail}', ['detail' => $detail]);
    }
}
