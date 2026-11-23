<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\FullReport;
use Intakt\Gates\GateSlots;
use Intakt\Gates\JsonNode;
use Intakt\Gates\Options;
use Intakt\Gates\PassedTree;
use Intakt\Gates\TreeFingerprint;
use Intakt\Gates\TreeWalk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\NativeClock;

#[CoversClass(CheckCommand::class)]
#[CoversClass(Options::class)]
#[UsesClass(PassedTree::class)]
#[UsesClass(TreeFingerprint::class)]
#[UsesClass(TreeWalk::class)]
#[UsesClass(JsonNode::class)]
final class CheckReuseTest extends SandboxTestCase
{
    private const string REUSED = '{"result":"pass","failed":[],"reused":"'.PassedTree::RECORD.'"}';

    // Because the audit fixture serves its own advisories, a proxy nobody listens on turns any request to Packagist into a failed test.
    private const array OFFLINE = ['http_proxy' => 'http://127.0.0.1:9', 'https_proxy' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9', 'no_proxy' => '', 'NO_PROXY' => ''];

    public function testASecondCheckOfAnIdenticalTreeThatAsksForReusePrintsAPassingSummaryNamingTheRecordAndStartsNoGate(): void
    {
        $sandbox = $this->committedSandbox("clean\n");
        $first = $this->check($sandbox);

        $second = $this->check($sandbox, '--reuse');

        self::assertSame([0, 0], [$first->exitCode(), $second->exitCode()], $first->output().$second->output());
        self::assertTrue($first->passed('dashes'), $first->output());
        self::assertSame([self::REUSED], $second->lines(), $second->output());
    }

    public function testASecondCheckThatDoesNotAskForReuseRunsEveryGateAgain(): void
    {
        $sandbox = $this->committedSandbox("clean\n");
        $this->check($sandbox);

        $run = $this->check($sandbox);

        self::assertFileExists($sandbox->root.'/'.PassedTree::RECORD);
        self::assertTrue($run->passed('dashes'), $run->output());
        self::assertStringNotContainsString('"reused"', $run->output());
    }

    public function testACheckAfterAnEditRunsTheGatesAgain(): void
    {
        $sandbox = $this->committedSandbox("clean\n");
        $this->check($sandbox);
        $sandbox->write('README.md', "edited\n");

        $run = $this->check($sandbox, '--reuse');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed('dashes'), $run->output());
        self::assertStringNotContainsString('"reused"', $run->output());
    }

    public function testANewFileThatOnlyGitInfoExcludeLeavesOutFailsAsAFreshRunWould(): void
    {
        $sandbox = $this->committedSandbox("clean\n");
        $sandbox->write('.git/info/exclude', "notes.md\n");
        $this->check($sandbox);
        $sandbox->write('notes.md', "a long dash \u{2014} here\n");

        $run = $this->check($sandbox, '--reuse');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('dashes'), $run->output());
    }

    #[DataProvider('gateInputsWhereTheCheckWrites')]
    public function testAnEditedGateInputWhereTheCheckWritesFailsAsAFreshRunWould(string $ignored, string $path, bool $tracked): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "clean\n");
        $sandbox->write('.gitignore', $ignored);
        // Because the folders above a counted path under var/ count too, the run that first creates the folder of the check notes nothing; that folder is there before, as after any earlier run.
        mkdir($sandbox->root.'/'.FullReport::FOLDER, 0o777, true);
        if ($tracked) {
            $sandbox->write($path, "clean\n");
            $sandbox->git('add', '--force', $path);
        }
        $sandbox->commitAll('feat: start');
        $this->waitForTheNextSecond();
        $first = $this->check($sandbox);
        $recorded = is_file($sandbox->root.'/'.PassedTree::RECORD);
        $sandbox->write($path, "a long dash \u{2014} here\n");

        $reused = $this->check($sandbox, '--reuse');
        $fresh = $this->check($sandbox);

        self::assertTrue($first->passed('dashes'), $first->output());
        self::assertSame($tracked, $recorded, 'Only a tree that ignores the folder of the check keeps a record.');
        self::assertSame([1, 1], [$reused->exitCode(), $fresh->exitCode()], $reused->output());
        self::assertTrue($reused->failed('dashes'), $reused->output());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function gateInputsWhereTheCheckWrites(): iterable
    {
        yield 'a tracked file under an ignored var/' => ["/var/\n", 'var/tracked.md', true];
        yield 'an untracked file under a var/ that is not ignored' => ['', 'var/notes.md', false];
        yield 'a tracked file under an ignored .phpunit.cache/' => ["/var/\n/.phpunit.cache/\n", '.phpunit.cache/notes.md', true];
        yield 'a tracked config/reference.php' => ["/var/\n/config/reference.php\n", 'config/reference.php', true];
    }

    public function testAnEditedFileUnderAnIgnoredVarStillReusesThePass(): void
    {
        $sandbox = $this->committedSandbox("clean\n");
        $this->check($sandbox);
        $sandbox->write('var/cache/notes.md', "a long dash \u{2014} here\n");

        $reused = $this->check($sandbox, '--reuse');
        $fresh = $this->check($sandbox);

        self::assertSame([self::REUSED], $reused->lines(), $reused->output());
        self::assertSame(0, $fresh->exitCode(), $fresh->output());
    }

    // Because the run that creates var/ changes the folder it checks, it notes nothing; the next run of the unchanged tree does.
    public function testTheCheckThatCreatesTheVarFolderNotesNoPassAndTheNextOneDoes(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', "clean\n");
        $sandbox->write('.gitignore', "/var/\n");
        $sandbox->commitAll('feat: start');
        $this->waitForTheNextSecond();

        $this->check($sandbox);
        $afterTheFirst = is_file($sandbox->root.'/'.PassedTree::RECORD);
        $this->waitForTheNextSecond();
        $this->check($sandbox);

        self::assertFalse($afterTheFirst);
        self::assertFileExists($sandbox->root.'/'.PassedTree::RECORD);
    }

    public function testAFailedCheckIsNeverReused(): void
    {
        $sandbox = $this->committedSandbox("a long dash \u{2014} here\n");
        $this->check($sandbox);

        $run = $this->check($sandbox, '--reuse');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('dashes'), $run->output());
        self::assertFileDoesNotExist($sandbox->root.'/'.PassedTree::RECORD);
    }

    public function testAReusedPassStillAuditsTheUnchangedLockFileAgainstTheAdvisoriesOfNow(): void
    {
        $advisories = $this->emptySandbox();
        $sandbox = $this->sandboxWith('vulnerable-dependency');
        $sandbox->write('composer.json', (string) json_encode(['name' => 'intakt/fixture', 'version' => '1.0.0', 'repositories' => [['type' => 'composer', 'url' => $advisories->root], ['packagist.org' => false]], 'require' => ['symfony/http-kernel' => '5.4.0']]));
        $advisories->write('packages.json', (string) json_encode(['packages' => [], 'metadata-url' => $advisories->root.'/p2/%package%.json', 'available-packages' => ['symfony/http-kernel'], 'security-advisories' => ['metadata' => true]]));
        $advisory = (string) file_get_contents($sandbox->root.'/advisories/p2/symfony/http-kernel.json');
        $advisories->write('p2/symfony/http-kernel.json', '{"packages": {}, "security-advisories": []}');
        $sandbox->write('.gitignore', "/var/\n");
        mkdir($sandbox->root.'/var');
        $sandbox->startRepository();
        $sandbox->commitAll('feat: start');
        $this->waitForTheNextSecond();
        $first = $sandbox->checkWith([GateSlots::VARIABLE => '1', ...self::OFFLINE], '--only=dashes,audit');

        $advisories->write('p2/symfony/http-kernel.json', $advisory);
        $second = $sandbox->checkWith([GateSlots::VARIABLE => '1', ...self::OFFLINE], '--only=dashes,audit', '--reuse');

        self::assertSame(0, $first->exitCode(), $first->output());
        self::assertFileExists($sandbox->root.'/'.PassedTree::RECORD, $first->output());
        self::assertSame(1, $second->exitCode(), $second->output());
        self::assertTrue($second->failed('audit'), $second->output());
        self::assertStringNotContainsString('"gate":"dashes"', $second->output());
        self::assertStringContainsString('"reused":"'.PassedTree::RECORD.'"', $second->output());
    }

    public function testTheJourneysRefuseToReuseAPass(): void
    {
        $run = $this->emptySandbox()->check('--journeys', '--reuse');

        self::assertSame(2, $run->exitCode(), $run->output());
        self::assertStringContainsString('--journeys runs alone, without --fast, --reuse,', $run->output());
    }

    public function testReuseIsOnlyAskedForByItsOwnArgument(): void
    {
        self::assertSame([false, true], [Options::fromArguments(['--fast'])->reuse, Options::fromArguments(['--fast', '--reuse'])->reuse]);
    }

    private function committedSandbox(string $readme): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('README.md', $readme);
        // Because a pass is kept only by a tree that ignores var/, and only by a run that did not have to create it.
        $sandbox->write('.gitignore', "/var/\n");
        mkdir($sandbox->root.'/var');
        $sandbox->commitAll('feat: start');
        $this->waitForTheNextSecond();

        return $sandbox;
    }

    private function check(Sandbox $sandbox, string ...$arguments): CheckRun
    {
        return $sandbox->checkWith([GateSlots::VARIABLE => '1'], '--only=dashes', ...array_values($arguments));
    }

    // Because a file written in the second a run starts records no pass, the first run starts in the next second.
    private function waitForTheNextSecond(): void
    {
        $clock = new NativeClock();
        $clock->sleep(1.02 - (float) $clock->now()->format('0.u'));
    }
}
