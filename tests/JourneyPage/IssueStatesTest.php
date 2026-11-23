<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(IssueStates::class)]
final class IssueStatesTest extends TestCase
{
    private string $folder = '';

    public function testItMapsEveryNumberOfTheStateFileToWhetherItIsOpen(): void
    {
        $this->record('[{"number":1,"state":"open"},{"number":2,"state":"closed"},{"number":3,"state":"closed"}]');

        self::assertSame([1 => true, 2 => false, 3 => false], new IssueStates()->openByNumber($this->folder));
    }

    public function testAMissingStateFileGivesNoStates(): void
    {
        self::assertSame([], new IssueStates()->openByNumber($this->folder));
    }

    public function testAnUnreadableStateFileGivesNoStates(): void
    {
        $this->record('not json');

        self::assertSame([], new IssueStates()->openByNumber($this->folder));
    }

    public function testTheJourneysIssuesFileAloneGivesNoStates(): void
    {
        new Filesystem()->dumpFile($this->folder.'/issues.json', '[[{"number":1,"state":"open"}]]');

        self::assertSame([], new IssueStates()->openByNumber($this->folder));
    }

    public function testAnItemOfTheWrongShapeIsSkipped(): void
    {
        $this->record('[{"number":"1","state":"open"},{"number":2,"state":"weird"},5,{"number":4,"state":"open"}]');

        self::assertSame([4 => true], new IssueStates()->openByNumber($this->folder));
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-states-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function record(string $json): void
    {
        new Filesystem()->dumpFile($this->folder.'/'.IssueStates::FILE, $json);
    }
}
