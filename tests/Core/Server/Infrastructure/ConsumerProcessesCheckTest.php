<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\CheckVerdict;
use Intakt\Core\Server\Infrastructure\ConsumerProcessesCheck;
use Intakt\Tests\Core\Server\FakeProcessRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsumerProcessesCheck::class)]
#[CoversClass(CheckVerdict::class)]
#[UsesClass(FakeProcessRoot::class)]
final class ConsumerProcessesCheckTest extends TestCase
{
    private const string MISSING_FIX = 'restart the container with docker compose restart intakt; outside the container, start them with messenger:consume async and messenger:consume scheduler_watcher scheduler_retention';
    private const string CANNOT_RUN_FIX = 'run the doctor inside the container: docker compose exec intakt php bin/console intakt:doctor';

    private ?FakeProcessRoot $root = null;

    public function testRunningAsyncAndSchedulerConsumersPass(): void
    {
        $verdict = $this->check(FakeProcessRoot::healthy());

        self::assertTrue($verdict->passes());
        self::assertNull($verdict->fix);
    }

    public function testAMissingAsyncConsumerFailsWithItsFixText(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher', 'scheduler_retention'],
        ]));

        self::assertSame('no async worker is running, so no review or build moves', $verdict->problem);
        self::assertSame(self::MISSING_FIX, $verdict->fix);
    }

    public function testAMissingSchedulerConsumerFailsWithItsFixText(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', 'bin/console', 'messenger:consume', 'async', '--quiet'],
            ['php', 'bin/console', 'messenger:consume', 'async', '--quiet'],
        ]));

        self::assertSame('the scheduler worker is not running, so pull requests are not watched', $verdict->problem);
        self::assertSame(self::MISSING_FIX, $verdict->fix);
    }

    public function testNoConsumerAtAllNamesBothMissingKindsOnOneProblem(): void
    {
        $verdict = $this->check(new FakeProcessRoot([['frankenphp', 'run'], ['php', 'bin/console', 'intakt:doctor']]));

        self::assertSame('no async worker is running, so no review or build moves; the scheduler worker is not running, so pull requests are not watched', $verdict->problem);
    }

    public function testAReceiverNameBeforeTheConsumeCommandDoesNotCountAsAConsumer(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['grep', 'async', 'messenger:consume'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ]));

        self::assertSame('no async worker is running, so no review or build moves', $verdict->problem);
    }

    public function testAMissingProcessRootFailsAsCannotRun(): void
    {
        $verdict = new ConsumerProcessesCheck(sys_get_temp_dir().'/intakt-no-such-proc')->run();

        self::assertSame('cannot run (the process list under '.sys_get_temp_dir().'/intakt-no-such-proc cannot be read)', $verdict->problem);
        self::assertSame(self::CANNOT_RUN_FIX, $verdict->fix);
    }

    public function testAnUnreadableProcessRootFailsAsCannotRun(): void
    {
        $this->root = FakeProcessRoot::healthy();
        chmod($this->root->path, 0o000);

        $verdict = new ConsumerProcessesCheck($this->root->path)->run();

        self::assertStringStartsWith('cannot run (the process list under ', (string) $verdict->problem);
    }

    public function testAProcessRootWithoutAnyProcessFailsAsCannotRun(): void
    {
        $verdict = $this->check(new FakeProcessRoot([]));

        self::assertStringStartsWith('cannot run (', (string) $verdict->problem);
        self::assertSame(self::CANNOT_RUN_FIX, $verdict->fix);
    }

    protected function tearDown(): void
    {
        $this->root?->remove();
    }

    private function check(FakeProcessRoot $root): CheckVerdict
    {
        $this->root = $root;

        return new ConsumerProcessesCheck($root->path)->run();
    }
}
