<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\CiDefinition;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\UseCase\ListCiChanges\ListCiChangesCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ListCiChangesCommand::class)]
#[UsesClass(CiDefinition::class)]
#[UsesClass(WorkflowFile::class)]
final class ListCiChangesCommandTest extends TestCase
{
    public function testEachChangedPathAWorkflowRunsIsListed(): void
    {
        $tester = $this->execute(['bin/check', 'src/Foo.php'], [__DIR__.'/Fixtures/workflows/contained.yml']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("ci bin/check\nCI change: yes\n", $tester->getDisplay());
    }

    public function testAChangeNoWorkflowRunsIsNoCiChange(): void
    {
        $tester = $this->execute(['src/Foo.php'], [__DIR__.'/Fixtures/workflows/contained.yml']);

        self::assertSame("CI change: no\n", $tester->getDisplay());
    }

    public function testAWorkflowThatCannotBeReadMakesEveryPathACiChange(): void
    {
        $tester = $this->execute(['src/Foo.php'], [__DIR__.'/Fixtures/workflows']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Failed to read file', $tester->getDisplay());
        self::assertStringEndsWith("ci src/Foo.php\nCI change: yes\n", $tester->getDisplay());
    }

    /**
     * @param list<string> $paths
     * @param list<string> $workflows
     */
    private function execute(array $paths, array $workflows): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ListCiChangesCommand(new CiDefinition(), new Filesystem()));
        $tester = new CommandTester($application->find('intakt:forge:ci-paths'));
        $tester->execute(['paths' => $paths, '--workflow' => $workflows]);

        return $tester;
    }
}
