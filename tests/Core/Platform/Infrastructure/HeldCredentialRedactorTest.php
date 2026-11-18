<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Infrastructure;

use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\HeldCredentialRedactor;
use Intakt\Core\Platform\Infrastructure\HeldCredentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeldCredentialRedactor::class)]
#[UsesClass(Redaction::class)]
#[UsesClass(HeldCredentials::class)]
final class HeldCredentialRedactorTest extends TestCase
{
    private const string NAME = 'INTAKT_TEST_API_KEY';

    protected function tearDown(): void
    {
        unset($_SERVER[self::NAME]);
    }

    public function testAHeldCredentialWithoutASecretShapeIsRedacted(): void
    {
        $_SERVER[self::NAME] = 'plainheldkeyvalue0123456789';

        self::assertSame('401 for key [redacted]', $this->redactor()->redact('401 for key plainheldkeyvalue0123456789'));
    }

    public function testACredentialHeldOnlyAfterTheRedactorWasBuiltIsStillRedacted(): void
    {
        $redactor = $this->redactor();
        $_SERVER[self::NAME] = 'plainheldkeyvalue0123456789';

        self::assertSame('[redacted]', $redactor->redact('plainheldkeyvalue0123456789'));
    }

    public function testASecretShapeIsRedactedWithoutBeingHeld(): void
    {
        self::assertSame('Authorization: [redacted]', $this->redactor()->redact('Authorization: Bearer abc123xyz'));
    }

    public function testTextWithoutACredentialIsKept(): void
    {
        $_SERVER[self::NAME] = 'plainheldkeyvalue0123456789';

        self::assertSame('commit a not fetched', $this->redactor()->redact('commit a not fetched'));
    }

    public function testAHeldAppSecretWithATrailingNewlineIsRedactedWhereItIsQuotedWithoutIt(): void
    {
        $previous = $_SERVER['APP_SECRET'] ?? null;
        $_SERVER['APP_SECRET'] = "probe-app-secret-value\n";

        try {
            $redacted = new HeldCredentialRedactor(new Redaction(), new HeldCredentials(['APP_SECRET']))
                ->redact('the judge saw probe-app-secret-value in the diff');
        } finally {
            if (null === $previous) {
                unset($_SERVER['APP_SECRET']);
            } else {
                $_SERVER['APP_SECRET'] = $previous;
            }
        }

        self::assertSame('the judge saw [redacted] in the diff', $redacted);
    }

    private function redactor(): HeldCredentialRedactor
    {
        return new HeldCredentialRedactor(new Redaction(), new HeldCredentials([self::NAME]));
    }
}
