<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Kernel;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Mcp\Contract\Tool\OnePanel;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use Mcp\Client;
use Mcp\Client\Builder;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\ConnectionException;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

// Covers nothing because the contract is the owner's server as an MCP client meets it: the SDK's own client over HTTP into the kernel.
#[Group('persistence')]
#[CoversNothing]
final class OwnerServerSdkClientTest extends KernelTestCase
{
    private TemporaryRepository $repository;

    private string $roundId;

    private string $ownerToken;

    private ?string $bearer = null;

    /** @var array<string, string> */
    private array $previous = [];

    private KernelHttpClient $kernelClient;

    public function testEveryToolIsListedAndTheStartToolIsAWriteThatMayDestroy(): void
    {
        $tools = $this->tools($this->connected(withWrites: true));

        self::assertSame(['list_projects', 'review_act_list', 'review_status', 'review_verdicts', 'start_review'], array_values(array_intersect(array_keys($tools), ['list_projects', 'review_act_list', 'review_status', 'review_verdicts', 'start_review'])));
        self::assertTrue($this->annotations($tools['review_status'])->readOnlyHint);
        self::assertFalse($this->annotations($tools['start_review'])->readOnlyHint);
        self::assertTrue($this->annotations($tools['start_review'])->destructiveHint);
    }

    public function testTheReadToolsAnswerWithoutAToken(): void
    {
        $client = $this->connected(withWrites: false);

        self::assertFalse($client->callTool('list_projects')->isError);
        self::assertSame('judged', $this->structured($client->callTool('review_status', ['roundId' => $this->roundId]))['state']);
        self::assertSame('changes', $this->firstVerdict($this->structured($client->callTool('review_verdicts', ['roundId' => $this->roundId]))));
        self::assertSame(['F1'], $this->actIds($this->structured($client->callTool('review_act_list', ['roundId' => $this->roundId]))));
    }

    public function testTheFindingsResourceIsReadThroughTheClient(): void
    {
        $result = $this->connected(withWrites: false)->readResource('intakt://rounds/'.$this->roundId.'/findings');

        self::assertSame('application/json', $result->contents[0]->mimeType);
    }

    public function testStartingAReviewWithTheOwnerTokenAnswersWithTheDecisionAndTheActList(): void
    {
        $result = $this->start($this->connected(
            withWrites: true,
            bearer: $this->ownerToken,
        ));

        self::assertFalse($result->isError);
        $answer = $this->structured($result);
        self::assertSame(1, $answer['round']);
        self::assertSame('changes', $answer['decision']);
        self::assertSame(['F1'], $this->actIds($answer));
    }

    public function testTheActListOfAStartedReviewIsReadBackThroughTheReadTool(): void
    {
        $client = $this->connected(
            withWrites: true,
            bearer: $this->ownerToken,
        );
        $started = $this->structured($this->start($client));

        $act = $this->structured($client->callTool('review_act_list', ['roundId' => $started['roundId']]));

        self::assertTrue($act['judged']);
        self::assertSame(['F1'], $this->actIds($act));
    }

    public function testStartingAReviewSendsProgressNotificationsBeforeTheResult(): void
    {
        $steps = [];

        $this->start($this->connected(
            withWrites: true,
            bearer: $this->ownerToken,
        ), onProgress: static function (float $progress, ?float $total, ?string $message) use (&$steps): void {
            $steps[] = [$progress, $total, $message];
        });

        self::assertSame([[0.0, 2.0, '0 of 1 reviewers finished.'], [1.0, 2.0, 'All reviewers finished, the judge is reading their findings.'], [2.0, 2.0, 'The round is judged.']], $steps);
    }

    public function testStartingAReviewWithoutTheTokenIsAnMcpErrorAndNothingRuns(): void
    {
        $result = $this->start($this->connected(withWrites: true));

        self::assertTrue($result->isError);
        self::assertStringContainsString('mcp_tool_write, which is not granted', $this->text($result));
        self::assertStringNotContainsString('#0', $this->text($result));
    }

    public function testStartingAReviewWithAWrongTokenIsRefused(): void
    {
        $client = $this->connected(
            withWrites: true,
            bearer: bin2hex(random_bytes(16)),
            connect: false,
        );

        $this->expectException(ConnectionException::class);
        $client->connect($this->transport());
    }

    public function testStartingAReviewWithTheTokenWhileTheConfigForbidsWritesIsAnMcpError(): void
    {
        $result = $this->start($this->connected(
            withWrites: false,
            bearer: $this->ownerToken,
        ));

        self::assertTrue($result->isError);
        self::assertStringContainsString('not granted', $this->text($result));
    }

    public function testATokenSentAsAToolArgumentGrantsNothing(): void
    {
        $result = $this->connected(withWrites: true)->callTool('start_review', [...$this->reviewArguments(), 'token' => $this->ownerToken, 'allow_write' => true]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('not granted', $this->text($result));
    }

    public function testAnUnknownRoundIsAnMcpErrorWithoutAStackTrace(): void
    {
        $result = $this->connected(withWrites: false)->callTool('review_status', ['roundId' => 'not-a-round']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('No review round is stored under the id not-a-round', $this->text($result));
        self::assertStringNotContainsString('#0', $this->text($result));
    }

    public function testARoundNumberThatIsNotNextIsAnMcpErrorWithoutAStackTrace(): void
    {
        $result = $this->start($this->connected(
            withWrites: true,
            bearer: $this->ownerToken,
        ), ['round' => 2]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Round 2 was asked for', $this->text($result));
        self::assertStringNotContainsString('#0', $this->text($result));
    }

    protected function setUp(): void
    {
        $this->ownerToken = bin2hex(random_bytes(16));
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('sample');
        $this->repository->commitFile('src/Feature.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();

        // Put back what .env set, because later tests of the process resolve the same variables.
        foreach ($this->previous as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }

        parent::tearDown();
    }

    private function connected(bool $withWrites, ?string $bearer = null, bool $connect = true): Client
    {
        $this->setEnvironment($withWrites ? 'mcp_tool_write' : 'mcp_tool_read');
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $this->useTestReviews();
        $this->seedRound();
        $this->bearer = $bearer;
        $this->kernelClient = new KernelHttpClient($kernel);
        $client = new Builder()->setProtocolVersion(ProtocolVersion::V2025_11_25)->build();

        if ($connect) {
            $client->connect($this->transport());
        }

        return $client;
    }

    private function transport(): HttpTransport
    {
        $headers = null === $this->bearer ? [] : ['Authorization' => 'Bearer '.$this->bearer];

        return new HttpTransport('http://localhost/mcp/owner', $headers, $this->kernelClient);
    }

    private function setEnvironment(string $access): void
    {
        foreach (['MCP_OWNER_TOKEN' => $this->ownerToken, 'MCP_OWNER_ACCESS' => $access] as $name => $value) {
            $current = $_SERVER[$name] ?? '';
            $this->previous[$name] = is_string($current) ? $current : '';
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    private function useTestReviews(): void
    {
        $container = self::getContainer();
        $container->set(ChangeReviews::class, $container->get('intakt.test.mcp.review_change'));
        $container->set(Panels::class, $container->get(OnePanel::class));
    }

    private function seedRound(): void
    {
        $fixture = new RoundFixture(self::getContainer());
        $round = $fixture->start(2);
        $this->roundId = $round->id()->toRfc4122();
        $findings = [new Finding('A', Severity::P1, null, null, null, 'Text of A.', FixableBy::Builder, false, null, null)];

        foreach ($fixture->bus->messages as $message) {
            $fixture->deliver($message, new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]));
        }

        $fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)])))(new JudgeRound($this->roundId));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function start(Client $client, array $extra = [], ?callable $onProgress = null): CallToolResult
    {
        return $client->callTool('start_review', $this->reviewArguments($extra), $onProgress);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function reviewArguments(array $extra = []): array
    {
        return ['review' => ['project' => 'alpha', 'task' => 'Add the thing.', 'change' => 'sample', 'repository' => $this->repository->path, ...$extra]];
    }

    /**
     * @return array<string, Tool>
     */
    private function tools(Client $client): array
    {
        $tools = [];

        foreach ($client->listTools()->tools as $tool) {
            $tools[$tool->name] = $tool;
        }

        ksort($tools);

        return $tools;
    }

    /**
     * @return array<mixed>
     */
    private function structured(CallToolResult $result): array
    {
        self::assertFalse($result->isError, $this->text($result));
        self::assertIsArray($result->structuredContent);

        return $result->structuredContent;
    }

    private function annotations(Tool $tool): ToolAnnotations
    {
        return $tool->annotations ?? self::fail($tool->name.' has no annotations.');
    }

    /**
     * @param array<mixed> $answer
     */
    private function firstVerdict(array $answer): mixed
    {
        $verdicts = $answer['verdicts'];
        self::assertIsArray($verdicts);
        $first = $verdicts[0];
        self::assertIsArray($first);
        $verdict = $first['verdict'];
        self::assertIsArray($verdict);

        return $verdict['verdict'];
    }

    /**
     * @param array<mixed> $answer
     *
     * @return list<mixed>
     */
    private function actIds(array $answer): array
    {
        self::assertIsArray($answer['act']);

        return array_column($answer['act'], 'id');
    }

    private function text(CallToolResult $result): string
    {
        return implode("\n", array_map(static fn (Content $content): string => $content instanceof TextContent && is_string($content->text) ? $content->text : '', $result->content));
    }
}
