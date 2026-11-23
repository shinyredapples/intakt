<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ShowSampleReview;

use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Mcp\Contract\OwnerClientLine;
use Intakt\Core\Mcp\Contract\OwnerToken;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Core\Review\UseCase\ShowSampleReview\ShowSampleReviewCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ShowSampleReviewCommand::class)]
#[UsesClass(OwnerToken::class)]
#[UsesClass(OwnerClientLine::class)]
#[UsesClass(SampleProject::class)]
final class ShowSampleReviewCommandTest extends TestCase
{
    private const string REPOSITORY = '/app/var/data/sample-repository';

    private string $ownerToken = '';

    private string $composeService = '';

    private ?string $keptFile = null;

    private string $configuration = '';

    public function testAfterAKeylessStartItNamesTheKeysToSetInsteadOfTheReviewCommand(): void
    {
        unlink($this->configuration);

        $display = $this->show()->getDisplay();

        self::assertStringNotContainsString('intakt:review', $display);
        self::assertStringContainsString('No sample project yet: set ANTHROPIC_API_KEY or OPENAI_API_KEY or HF_TOKEN and start again to write it, then run intakt:sample:show again.', $display);
    }

    public function testAfterAKeylessStartItStillNamesTheDashboardAndTheMcpSetup(): void
    {
        unlink($this->configuration);
        $this->ownerToken = 'demo-owner-token';

        $lines = $this->framedLines();

        self::assertSame('Open the dashboard: http://localhost:7781', $lines[0]);
        self::assertStringStartsWith('Connect an MCP client: ', $lines[2]);
    }

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

    public function testWithTheVariableEmptyAndTheTokenFilePresentTheMcpLineShowsThatToken(): void
    {
        $file = sys_get_temp_dir().'/'.uniqid('owner-token-', true);
        file_put_contents($file, str_repeat('ab', 32)."\n");
        $this->keptFile = $file;

        try {
            $lines = $this->framedLines();
        } finally {
            unlink($file);
        }

        self::assertSame('Connect an MCP client: claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header "Authorization: Bearer '.str_repeat('ab', 32).'"', $lines[2]);
    }

    public function testWithNeitherTheVariableNorAValidFileOneLineSaysHowToGetTheToken(): void
    {
        $tester = $this->show();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No MCP owner token found: set MCP_OWNER_TOKEN in .env.local', $tester->getDisplay());
        self::assertStringNotContainsString('mcp/owner', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->configuration = sys_get_temp_dir().'/'.uniqid('intakt-', true).'.yaml';
        file_put_contents($this->configuration, "projects: {}\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->configuration)) {
            unlink($this->configuration);
        }
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
        $application->addCommand(new ShowSampleReviewCommand('http://localhost:7781', new OwnerToken($this->ownerToken, $this->keptFile), $this->composeService, 7781, new SampleProject(self::createStub(EnabledAccounts::class), null), $this->configuration));
        $tester = new CommandTester($application->find('intakt:sample:show'));
        $tester->execute(['repository' => self::REPOSITORY]);

        return $tester;
    }
}
