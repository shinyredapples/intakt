<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Plugin\GitHubForge\ScriptedGitHub;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

// The install's own ledger on SQLite and its `intakt:ledger:events` command, because what the push recorded is the evidence of what it did to the branch.
#[CoversNothing]
#[Group('persistence')]
final class PushToAStubbedGitHubOnSqliteTest extends KernelTestCase
{
    private PushScenario $scenario;
    private ScriptedGitHub $github;
    private Ledger $ledger;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);
        $this->ledger = $ledger;
        $this->scenario = new PushScenario();
        $this->github = new ScriptedGitHub();
    }

    protected function tearDown(): void
    {
        $this->scenario->remove();
        parent::tearDown();
    }

    public function testAPushForABranchWithAForeignOpenPullRequestRecordsAndPushesNothingOnSqlite(): void
    {
        $this->github->openPulls = [
            ['number' => 7, 'head' => ['ref' => PushScenario::BRANCH], 'base' => ['ref' => 'main'], 'user' => ['login' => 'intakt[bot]']],
            ['number' => 8, 'head' => ['ref' => PushScenario::BRANCH], 'base' => ['ref' => 'release'], 'user' => ['login' => 'loop[bot]']],
        ];

        try {
            (void) $this->scenario->pushThrough($this->github->opener(), $this->ledger)->push($this->scenario->request());
            self::fail('A branch with a foreign open pull request must not be pushed.');
        } catch (ForgeWriteFailed $refused) {
            self::assertStringContainsString('another account opened', $refused->getMessage());
        }

        self::assertNull($this->scenario->remoteTip());
        self::assertSame([], $this->github->writes);
        self::assertStringContainsString('0 events', $this->recordedEvents());
    }

    public function testAPushForABranchWithThisAppsOwnOpenPullRequestUpdatesItAndRecordsItOnSqlite(): void
    {
        $this->github->openPulls = [['number' => 7, 'head' => ['ref' => PushScenario::BRANCH], 'base' => ['ref' => 'main'], 'user' => ['login' => 'intakt[bot]']]];

        $pushed = $this->scenario->pushThrough($this->github->opener(), $this->ledger)->push($this->scenario->request());

        self::assertInstanceOf(PushedBranch::class, $pushed);
        self::assertSame($pushed->head, $this->scenario->remoteTip());
        self::assertSame(['PATCH https://api.github.com/repos/acme/widgets/pulls/7'], array_column($this->github->writes, 0));
        $events = $this->recordedEvents();
        self::assertSame(1, preg_match_all('/ push_intended /', $events));
        self::assertSame(1, preg_match_all('/ branch_pushed /', $events));
        self::assertSame(1, preg_match_all('/ change_opened \{[^}]*pull\\\\\/7/', $events));
    }

    private function recordedEvents(): string
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('intakt:ledger:events'));
        $tester->execute([]);

        return $tester->getDisplay();
    }
}
