<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

#[CoversClass(RecordingHub::class)]
final class RecordingHubTest extends TestCase
{
    private const string PUBLIC_URL = 'http://localhost:7781/.well-known/mercure';

    private string $directory = '';

    /** @var list<Update> */
    private array $posted = [];

    public function testAPublishedUpdateIsReadBackInTheOrderItWasPublished(): void
    {
        $hub = $this->recordingHub();

        $hub->publish(new Update('review/rounds/1', '<turbo-stream>first</turbo-stream>', true));
        $hub->publish(new Update(['review/rounds/1', 'review/rounds/2'], '<turbo-stream>second</turbo-stream>'));

        self::assertEquals(
            [new Update('review/rounds/1', '<turbo-stream>first</turbo-stream>', true), new Update(['review/rounds/1', 'review/rounds/2'], '<turbo-stream>second</turbo-stream>')],
            RecordingHub::updatesIn($this->directory),
        );
    }

    public function testAPublishedUpdateNeverReachesTheHubItStandsIn(): void
    {
        $this->recordingHub()->publish(new Update('review/rounds/1', 'data'));

        self::assertSame([], $this->posted);
    }

    public function testNoUpdateIsReadBeforeAnythingWasPublished(): void
    {
        self::assertSame([], RecordingHub::updatesIn($this->directory));
    }

    public function testARecordingThatIsNotAnUpdateIsRefused(): void
    {
        new Filesystem()->dumpFile($this->directory.'/'.RecordingHub::FILE, '{"topics":"review/rounds/1","data":"x","private":false}'.PHP_EOL);

        $this->expectException(UnexpectedValueException::class);

        RecordingHub::updatesIn($this->directory);
    }

    public function testAnUpdateBeingAppendedIsReadOnlyOnceItIsComplete(): void
    {
        new Filesystem()->mkdir($this->directory);
        $writer = <<<'PHP'
            $handle = fopen($argv[1], 'ab');
            flock($handle, LOCK_EX);
            fwrite($handle, '{"topics":["review/rounds/1"],');
            fflush($handle);
            echo "ready\n";
            usleep(300000);
            fwrite($handle, '"data":"changed","private":true}'."\n");
            flock($handle, LOCK_UN);
            fclose($handle);
            PHP;
        $process = new Process([PHP_BINARY, '-r', $writer, '--', $this->directory.'/'.RecordingHub::FILE]);
        $process->start();
        $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready'));

        $updates = RecordingHub::updatesIn($this->directory);
        $process->wait();

        self::assertEquals([new Update('review/rounds/1', 'changed', true)], $updates);
    }

    public function testTheSubscriberSettingsAreTheHubItStandsIn(): void
    {
        $hub = $this->recordingHub();

        self::assertSame([self::PUBLIC_URL, 'mercure_access_token'], [$hub->getPublicUrl(), $hub->getCookieName()]);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-recording-hub-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function recordingHub(): RecordingHub
    {
        $posted = function (Update $update): string {
            $this->posted[] = $update;

            return 'posted';
        };

        return new RecordingHub(new MockHub(self::PUBLIC_URL, new StaticTokenProvider('token'), $posted, cookieName: 'mercure_access_token'), $this->directory);
    }
}
