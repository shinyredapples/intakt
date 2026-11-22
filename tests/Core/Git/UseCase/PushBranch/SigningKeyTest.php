<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Closure;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\UseCase\PushBranch\GuardedRun;
use Intakt\Core\Git\UseCase\PushBranch\SigningKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(SigningKey::class)]
#[CoversClass(GuardedRun::class)]
#[UsesClass(BranchNotPushed::class)]
final class SigningKeyTest extends TestCase
{
    private const string PAYLOAD = "tree of a commit\n";

    private string $folder;
    private string $key;
    private ProgramOnPath $programs;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-signing-'.bin2hex(random_bytes(6));
        mkdir($this->folder);
        $this->key = PushScenario::generateKey($this->folder.'/orchestrator');
        $this->programs = new ProgramOnPath();
    }

    protected function tearDown(): void
    {
        $this->programs->remove();
        new Filesystem()->remove($this->folder);
    }

    public function testASignatureOfTheKeyIsMadeAndVerifiesWithinTheLimit(): void
    {
        $key = new SigningKey($this->key, new Filesystem(), new GuardedRun());

        self::assertTrue($key->signed(self::PAYLOAD, $key->sign(self::PAYLOAD)));
    }

    public function testASigningRunKilledByASignalStopsThePushAndNamesNoKeyFile(): void
    {
        $this->programs->killOn('ssh-keygen', 'sign');

        $stopped = $this->stopped(fn (): string => new SigningKey($this->key, new Filesystem(), new GuardedRun())->sign(self::PAYLOAD));

        self::assertSame('The branch was not pushed: ssh-keygen was killed by signal 15', $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    public function testACheckOfASignatureKilledByASignalStopsThePushInsteadOfCallingTheSignatureBad(): void
    {
        $key = new SigningKey($this->key, new Filesystem(), new GuardedRun());
        $signature = $key->sign(self::PAYLOAD);
        $this->programs->killOn('ssh-keygen', 'verify');

        $stopped = $this->stopped(static fn (): bool => $key->signed(self::PAYLOAD, $signature));

        self::assertSame('The branch was not pushed: ssh-keygen was killed by signal 15', $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    public function testAReadOfTheKeyThatHangsPastTheLimitStopsThePushAndNamesNoKeyFile(): void
    {
        $this->programs->hangOn('ssh-keygen', '-y');

        $stopped = $this->stopped(fn (): string => new SigningKey($this->key, new Filesystem(), new GuardedRun(), 0.2)->sign(self::PAYLOAD));

        self::assertSame('The branch was not pushed: ssh-keygen timed out after 0.2 seconds', $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    public function testACheckOfASignatureThatHangsPastTheLimitStopsThePush(): void
    {
        $signature = new SigningKey($this->key, new Filesystem(), new GuardedRun())->sign(self::PAYLOAD);
        $this->programs->hangOn('ssh-keygen', 'verify');

        $stopped = $this->stopped(fn (): bool => new SigningKey($this->key, new Filesystem(), new GuardedRun(), 0.2)->signed(self::PAYLOAD, $signature));

        self::assertSame('The branch was not pushed: ssh-keygen timed out after 0.2 seconds', $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    public function testAnSshKeygenThatIsNotInstalledStopsThePushAndNamesNoKeyFile(): void
    {
        $this->programs->hideEveryOtherProgram();

        $stopped = $this->stopped(fn (): string => new SigningKey($this->key, new Filesystem(), new GuardedRun())->sign(self::PAYLOAD));

        self::assertSame('The branch was not pushed: the signing key cannot be read', $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    /**
     * @param Closure(): mixed $call
     */
    private function stopped(Closure $call): BranchNotPushed
    {
        try {
            $call();
        } catch (BranchNotPushed $stopped) {
            self::assertStringNotContainsString($this->key, $stopped->getMessage());

            return $stopped;
        }

        self::fail('The call must end as a branch that was not pushed.');
    }
}
