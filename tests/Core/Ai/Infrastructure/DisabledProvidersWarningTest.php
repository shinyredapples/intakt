<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\DisabledProvidersWarning;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(DisabledProvidersWarning::class)]
final class DisabledProvidersWarningTest extends TestCase
{
    public function testEachDisabledProviderIsWrittenWhenACommandStarts(): void
    {
        $output = new BufferedOutput();

        new DisabledProvidersWarning(['a' => 'first message', 'b' => 'second message'])->onConsoleCommand($this->eventWith($output));

        self::assertSame("first message\nsecond message\n", $output->fetch());
    }

    public function testNothingIsWrittenWhenEveryProviderIsEnabled(): void
    {
        $output = new BufferedOutput();

        new DisabledProvidersWarning([])->onConsoleCommand($this->eventWith($output));

        self::assertSame('', $output->fetch());
    }

    private function eventWith(BufferedOutput $output): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command('probe'), new ArrayInput([]), $output);
    }
}
