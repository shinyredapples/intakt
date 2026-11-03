<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\RedactedJsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(RedactedJsonFormatter::class)]
#[UsesClass(Redaction::class)]
final class LogWiringTest extends KernelTestCase
{
    public function testTheLogIsWrittenToTheServiceOutput(): void
    {
        self::assertSame('php://stderr', $this->handler()->getUrl());
    }

    public function testTheLogLineIsRedactedWithTheInstallsOwnCredential(): void
    {
        $secret = $_SERVER['APP_SECRET'];
        self::assertIsString($secret);
        self::assertNotSame('', $secret);

        $line = $this->handler()->getFormatter()->format(new LogRecord(new DateTimeImmutable('2026-11-03T10:00:00+00:00'), 'app', Level::Error, 'Leaked '.$secret.'.'));

        self::assertIsString($line);
        self::assertStringContainsString('Leaked [redacted].', $line);
    }

    private function handler(): StreamHandler
    {
        $handler = self::getContainer()->get('monolog.handler.main');
        self::assertInstanceOf(StreamHandler::class, $handler);

        return $handler;
    }
}
