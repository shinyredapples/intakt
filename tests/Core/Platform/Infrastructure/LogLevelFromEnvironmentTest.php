<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Infrastructure;

use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\HeldCredentials;
use Intakt\Core\Platform\Infrastructure\LogLevelFromEnvironment;
use Intakt\Core\Platform\Infrastructure\RedactedJsonFormatter;
use Intakt\Tests\Support\KernelTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\HttpKernel\Log\Logger;

#[CoversClass(LogLevelFromEnvironment::class)]
#[UsesClass(RedactedJsonFormatter::class)]
#[UsesClass(Redaction::class)]
#[UsesClass(HeldCredentials::class)]
final class LogLevelFromEnvironmentTest extends KernelTestCase
{
    private const string VARIABLE = 'INTAKT_LOG_LEVEL';

    private const string UNKNOWN_LEVEL_MESSAGE = 'INTAKT_LOG_LEVEL is set to a value that is not a log level: use one of emergency, alert, critical, error, warning, notice, info, debug.';

    /** @var array{server: array<mixed>, env: array<mixed>} */
    private array $previous = ['server' => [], 'env' => []];

    public function testAnUnsetLogLevelGivesWarning(): void
    {
        $level = $this->levelFrom(null);

        self::assertSame(LogLevel::WARNING, $level);
    }

    public function testAnEmptyLogLevelFailsWithAMessageNamingEveryAcceptedLevel(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(self::UNKNOWN_LEVEL_MESSAGE));

        $this->levelFrom('');
    }

    #[DataProvider('psrLevels')]
    public function testEveryPsrLevelIsAcceptedAsTheLogLevel(string $psrLevel): void
    {
        $level = $this->levelFrom($psrLevel);

        self::assertSame($psrLevel, $level);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function psrLevels(): iterable
    {
        foreach (array_filter(new ReflectionClass(LogLevel::class)->getConstants(), is_string(...)) as $level) {
            yield $level => [$level];
        }
    }

    public function testAnUnknownLogLevelFailsWithAMessageNamingEveryAcceptedLevel(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(self::UNKNOWN_LEVEL_MESSAGE));

        $this->levelFrom('loud');
    }

    public function testTheFailureMessageLeavesTheRejectedValueOut(): void
    {
        try {
            $this->levelFrom('pasted-by-mistake');
            self::fail('An unknown level was accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertStringNotContainsString('pasted-by-mistake', $failure->getMessage());
        }
    }

    public function testALevelInCapitalsIsNotALogLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->levelFrom('INFO');
    }

    public function testInfoFromTheEnvironmentLetsAnInfoRecordThroughTheRedactedLogger(): void
    {
        $output = $this->logInfoAt($this->levelFrom('info'));

        self::assertStringContainsString('"message":"Memory is off."', $output);
    }

    public function testTheDefaultLevelDropsAnInfoRecord(): void
    {
        $output = $this->logInfoAt($this->levelFrom(null));

        self::assertSame('', $output);
    }

    public function testTheContainerBuildsTheLoggerWhenTheEnvironmentNamesALevel(): void
    {
        $_SERVER[self::VARIABLE] = $_ENV[self::VARIABLE] = 'info';

        $logger = self::getContainer()->get('logger');

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testAnUnknownLogLevelFailsWhenTheContainerBuildsTheLogger(): void
    {
        $_SERVER[self::VARIABLE] = $_ENV[self::VARIABLE] = 'loud';

        $this->expectExceptionObject(new InvalidArgumentException(self::UNKNOWN_LEVEL_MESSAGE));

        self::getContainer()->get('logger');
    }

    protected function setUp(): void
    {
        $this->previous = ['server' => $_SERVER, 'env' => $_ENV];
    }

    protected function tearDown(): void
    {
        $this->restore($_SERVER, $this->previous['server']);
        $this->restore($_ENV, $this->previous['env']);
        parent::tearDown();
    }

    private function levelFrom(?string $value): string
    {
        return new LogLevelFromEnvironment()->getEnv('log_level', self::VARIABLE, static fn (string $name): string => $value ?? throw new EnvNotFoundException(sprintf('Environment variable not found: "%s".', $name)));
    }

    private function logInfoAt(string $minLevel): string
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $formatter = new RedactedJsonFormatter(new Redaction(), new MockClock('2026-11-23 10:00:00 UTC'), new HeldCredentials([]));

        new Logger($minLevel, $stream, $formatter)->info('Memory is off.');
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * @param array<mixed> $target
     * @param array<mixed> $previous
     */
    private function restore(array &$target, array $previous): void
    {
        if (array_key_exists(self::VARIABLE, $previous)) {
            $target[self::VARIABLE] = $previous[self::VARIABLE];

            return;
        }

        unset($target[self::VARIABLE]);
    }
}
