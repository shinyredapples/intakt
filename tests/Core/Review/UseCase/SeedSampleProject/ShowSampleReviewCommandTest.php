<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\SeedSampleProject;

use Intakt\Core\Review\UseCase\SeedSampleProject\ShowSampleReviewCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ShowSampleReviewCommand::class)]
final class ShowSampleReviewCommandTest extends TestCase
{
    private const string REPOSITORY = '/app/var/data/sample-repository';

    private string $ownerToken = '';

    private string $composeService = '';

    public function testOutsideTheComposeDemoItPrintsTheReviewCommandAsTheConsoleRunsIt(): void
    {
        $display = $this->show()->getDisplay();

        self::assertStringContainsString('Review the sample change: php bin/console intakt:review sample-change --project=sample --repository='.self::REPOSITORY.' --base=main --task="', $display);
    }

    public function testInTheComposeDemoItPrintsTheReviewCommandAsTheHostShellRunsIt(): void
    {
        $this->composeService = 'intakt';

        $display = $this->show()->getDisplay();

        self::assertStringContainsString('Review the sample change: docker compose exec intakt php bin/console intakt:review sample-change --project=sample --repository='.self::REPOSITORY.' --base=main --task="', $display);
    }

    public function testTheReviewCommandStaysOnOneLineSoItCanBeCopiedWhole(): void
    {
        $line = array_find(explode("\n", $this->show()->getDisplay()), static fn (string $line): bool => str_contains($line, 'intakt:review'));

        self::assertIsString($line);
        self::assertStringEndsWith('."', rtrim($line));
    }

    public function testItNamesTheDashboardAddressNextToTheReviewCommand(): void
    {
        $lines = $this->framedLines();

        self::assertSame('Open the dashboard: http://localhost:7781', $lines[0]);
        self::assertStringStartsWith('Review the sample change: ', $lines[1]);
    }

    public function testTheBlockIsFramedByRulesSoItStandsOutOfTheLog(): void
    {
        $lines = array_values(array_filter(explode("\n", $this->show()->getDisplay()), static fn (string $line): bool => '' !== trim($line)));

        self::assertMatchesRegularExpression('/^=+$/', array_first($lines) ?? '');
        self::assertMatchesRegularExpression('/^=+$/', array_last($lines) ?? '');
    }

    public function testItPrintsTheMcpAddressAndTheOwnerTokenNextToTheReviewCommand(): void
    {
        $this->ownerToken = 'demo-owner-token';

        $lines = $this->framedLines();

        self::assertSame('Connect an MCP client: claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header "Authorization: Bearer demo-owner-token"', $lines[2]);
    }

    public function testItPrintsNoMcpSetupWhenNoOwnerTokenIsSet(): void
    {
        $tester = $this->show();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('mcp/owner', $tester->getDisplay());
    }

    /**
     * @return list<string>
     */
    private function framedLines(): array
    {
        $lines = array_filter(explode("\n", $this->show()->getDisplay()), static fn (string $line): bool => '' !== trim($line) && 1 !== preg_match('/^=+$/', $line));

        return array_values(array_map(trim(...), $lines));
    }

    private function show(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ShowSampleReviewCommand('http://localhost:7781', $this->ownerToken, $this->composeService, 7781));
        $tester = new CommandTester($application->find('intakt:sample:show'));
        $tester->execute(['repository' => self::REPOSITORY]);

        return $tester;
    }
}
