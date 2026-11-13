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
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(DisabledProvidersWarning::class)]
final class DisabledProvidersWarningTest extends TestCase
{
    public function testEachDisabledProviderIsWrittenWhenACommandStarts(): void
    {
        $output = new BufferedOutput();

        new DisabledProvidersWarning(['a' => 'first message', 'b' => 'second message'], [])->onConsoleCommand($this->eventWith($output, 'intakt:ai:tiers'));

        self::assertSame("first message\nsecond message\n", $output->fetch());
    }

    public function testEachDisabledAccountIsWrittenAfterTheDisabledProviders(): void
    {
        $output = new BufferedOutput();

        new DisabledProvidersWarning(['a' => 'provider message'], ['account message'])->onConsoleCommand($this->eventWith($output, 'intakt:ai:tiers'));

        self::assertSame("provider message\naccount message\n", $output->fetch());
    }

    public function testNothingIsWrittenWhenEveryProviderIsEnabled(): void
    {
        $output = new BufferedOutput();

        new DisabledProvidersWarning([], [])->onConsoleCommand($this->eventWith($output, 'intakt:ai:tiers'));

        self::assertSame('', $output->fetch());
    }

    public function testAConsoleOutputGetsTheMessagesOnItsErrorOutputOnly(): void
    {
        $errors = new BufferedOutput();
        $output = $this->createMock(ConsoleOutputInterface::class);
        $output->expects(self::atLeastOnce())->method('getErrorOutput')->willReturn($errors);
        $output->expects(self::never())->method('write');
        $output->expects(self::never())->method('writeln');

        new DisabledProvidersWarning(['a' => 'first message'], [])->onConsoleCommand($this->eventWith($output, 'intakt:ai:tiers'));

        self::assertSame("first message\n", $errors->fetch());
    }

    public function testTheReviewCommandPrintsNoProviderNoteBeforeItsResult(): void
    {
        $output = new BufferedOutput();

        new DisabledProvidersWarning(['a' => 'first message'], ['account message'])->onConsoleCommand($this->eventWith($output, 'intakt:review'));

        self::assertSame('', $output->fetch());
    }

    public function testTheProbeCommandStillNamesEachDisabledProvider(): void
    {
        $output = new BufferedOutput();

        new DisabledProvidersWarning(['a' => 'first message'], [])->onConsoleCommand($this->eventWith($output, 'intakt:vendor:probe'));

        self::assertSame("first message\n", $output->fetch());
    }

    public function testACommandWithoutANamePrintsNoProviderNote(): void
    {
        $output = new BufferedOutput();

        new DisabledProvidersWarning(['a' => 'first message'], [])->onConsoleCommand(new ConsoleCommandEvent(null, new ArrayInput([]), $output));

        self::assertSame('', $output->fetch());
    }

    private function eventWith(OutputInterface $output, string $command): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($command), new ArrayInput([]), $output);
    }
}
