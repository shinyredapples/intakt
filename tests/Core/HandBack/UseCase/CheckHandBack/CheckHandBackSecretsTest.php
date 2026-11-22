<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\AttributionLines;
use Intakt\Core\HandBack\Domain\BundleHeader;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\GivenHistory;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BundleSecretScan::class)]
#[CoversClass(CheckHandBack::class)]
#[CoversClass(GivenHistory::class)]
#[CoversClass(RepositoryGit::class)]
#[CoversClass(StrippedCommits::class)]
#[UsesClass(BundleFile::class)]
#[UsesClass(IncomingObjects::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(RefusedHandBack::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(HandBackRefusal::class)]
#[UsesClass(HandBackNotChecked::class)]
#[UsesClass(AttributionLines::class)]
#[UsesClass(BundleHeader::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(JobCredentials::class)]
final class CheckHandBackSecretsTest extends TestCase
{
    private const string NO_RULES = "title = \"no rules\"\n";

    private BuildHistory $history;

    public function testATokenInACommitMessageIsRefused(): void
    {
        $this->history->commit('Use the key '.$this->fakeKey());

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsSecret), $result);
    }

    public function testAKeyThatALaterCommitDeletesIsRefused(): void
    {
        $this->history->commitFile('settings.php', "<?php\nreturn ['key' => '".$this->fakeKey()."'];\n", 'Add the settings');
        $this->history->commitFile('settings.php', "<?php\nreturn [];\n", 'Drop the key');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsSecret), $result);
    }

    public function testAKeyAfterALargeBinaryBlobInTheSameFileIsRefused(): void
    {
        $this->history->commitFile('data.bin', $this->largeBinary()."\nkey = ".$this->fakeKey()."\n", 'Add the data');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsSecret), $result);
    }

    public function testAKeyInAFileCommittedAfterALargeBinaryFileIsRefused(): void
    {
        $this->history->commitFile('data.bin', $this->largeBinary(), 'Add the data');
        $this->history->commitFile('settings.txt', 'key = '.$this->fakeKey()."\n", 'Add the settings');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsSecret), $result);
    }

    public function testScannerSettingsAndAllowCommentsTheBuildCommittedAreIgnored(): void
    {
        $this->history->commitFile('.gitleaks.toml', self::NO_RULES, 'Configure the scanner');
        $this->history->commitFile('.gitleaksignore', "*\n", 'Ignore everything');
        $this->history->commitFile('key.txt', 'key = '.$this->fakeKey()." # gitleaks\x3aallow\n", 'Add the key');

        $result = $this->check($this->history->bundle('main..task'));

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsSecret), $result);
    }

    public function testScannerSettingsFromTheEnvironmentAreIgnored(): void
    {
        $this->history->commit('Use the key '.$this->fakeKey());
        putenv('GITLEAKS_CONFIG_TOML='.self::NO_RULES);
        $_SERVER['GITLEAKS_CONFIG_TOML'] = self::NO_RULES;

        try {
            $result = $this->check($this->history->bundle('main..task'));
        } finally {
            putenv('GITLEAKS_CONFIG_TOML');
            unset($_SERVER['GITLEAKS_CONFIG_TOML']);
        }

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsSecret), $result);
    }

    public function testAKeyMainAlreadyHoldsDoesNotRefuseAMergeOfMain(): void
    {
        $this->history->commit('First');
        $this->history->checkout('main');
        $this->history->commitFile('main.txt', 'key = '.$this->fakeKey()."\n", 'On main');
        $this->history->push('main');
        $this->history->checkout('task');
        $head = $this->history->merge('main');

        $result = $this->checker()->check(new HandBack($this->history->mirror, 'task', $this->history->bundle('task', '^main'), true, new JobCredentials()));

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
    }

    public function testTheSecretScanRefusalNamesNoValueFromTheBundle(): void
    {
        $key = $this->fakeKey();
        $this->history->commit('Use the key '.$key);

        $result = $this->check($this->history->bundle('main..task'));

        self::assertInstanceOf(RefusedHandBack::class, $result);
        self::assertStringNotContainsString($key, $result->reason());
        self::assertStringNotContainsString($key, $result->refusal->value);
    }

    public function testAMissingScannerFailsClosed(): void
    {
        $this->history->commit('First');

        $this->expectException(HandBackNotChecked::class);

        (void) $this->checker('intakt-missing-scanner')->check($this->handBack($this->history->bundle('main..task')));
    }

    public function testAScannerThatFailsFailsClosed(): void
    {
        $this->history->commit('First');

        $this->expectException(HandBackNotChecked::class);

        (void) $this->checker('false')->check($this->handBack($this->history->bundle('main..task')));
    }

    public function testAMountedCredentialInACommitMessageIsRedacted(): void
    {
        $credential = $this->mountedCredential();
        $this->history->commit("Read with {$credential} here");

        $result = $this->check($this->history->bundle('main..task'), $credential);

        self::assertInstanceOf(AcceptedHandBack::class, $result);
        self::assertSame(1, $result->rewrittenCommits);
        self::assertStringEndsWith("\n\nRead with ".Redaction::MARKER." here\n", $this->history->inMirror('cat-file', 'commit', $result->head)."\n");
    }

    public function testAMountedCredentialInTheAuthorIsRedacted(): void
    {
        $credential = $this->mountedCredential();
        $this->history->commitByAuthor("{$credential} <someone@example.invalid>", 'First');

        $result = $this->check($this->history->bundle('main..task'), $credential);

        self::assertInstanceOf(AcceptedHandBack::class, $result);
        self::assertStringContainsString('author '.Redaction::MARKER.' <someone@example.invalid>', $this->history->inMirror('cat-file', 'commit', $result->head));
    }

    public function testTheLongerOfTwoMountedCredentialsIsRedactedWhole(): void
    {
        $short = $this->mountedCredential();
        $this->history->commit("Read with {$short}-and-more");

        $result = $this->check($this->history->bundle('main..task'), $short, $short.'-and-more');

        self::assertInstanceOf(AcceptedHandBack::class, $result);
        self::assertStringEndsWith("\n\nRead with ".Redaction::MARKER."\n", $this->history->inMirror('cat-file', 'commit', $result->head)."\n");
    }

    public function testAMountedCredentialInAFileIsRefused(): void
    {
        $credential = $this->mountedCredential();
        $this->history->commitFile('notes.txt', "token {$credential}\n", 'Add notes');

        $result = $this->check($this->history->bundle('main..task'), $credential);

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsCredential), $result);
        self::assertStringNotContainsString($credential, HandBackRefusal::HoldsCredential->reason());
    }

    public function testAMountedCredentialInAFileNameIsRefused(): void
    {
        $credential = $this->mountedCredential();
        $this->history->commitFile($credential.'.txt', "notes\n", 'Add notes');

        $result = $this->check($this->history->bundle('main..task'), $credential);

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsCredential), $result);
    }

    public function testAMountedCredentialInAFileALaterCommitDeletesIsRefused(): void
    {
        $credential = $this->mountedCredential();
        $this->history->commitFile('notes.txt', "token {$credential}\n", 'Add notes');
        $this->history->commitFile('notes.txt', "no token\n", 'Drop the token');

        $result = $this->check($this->history->bundle('main..task'), $credential);

        self::assertEquals(new RefusedHandBack(HandBackRefusal::HoldsCredential), $result);
    }

    public function testABundleWithoutTheMountedCredentialIsAcceptedUnchanged(): void
    {
        $head = $this->history->commitFile('notes.txt', "notes\n", 'Add notes');

        $result = $this->check($this->history->bundle('main..task'), $this->mountedCredential());

        self::assertEquals(new AcceptedHandBack(null, $head, 0), $result);
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    // Built at run time and random, so neither this file nor the repository's history holds a key the secrets gates would find.
    private function fakeKey(): string
    {
        return 'sk_live_'.bin2hex(random_bytes(12));
    }

    // Built at run time, so the repository holds no large file; NUL runs, so git and the scanner both see binary content. It repeats one random block, so it compresses readily and only its raw size is large.
    private function largeBinary(): string
    {
        return str_repeat(random_bytes(4096).str_repeat("\0", 4096), 640);
    }

    // No known shape, so only the redaction of mounted credentials can find it, never the scanner.
    private function mountedCredential(): string
    {
        return 'mounted-'.bin2hex(random_bytes(8));
    }

    private function check(string $bundle, string ...$credentials): AcceptedHandBack|RefusedHandBack
    {
        return $this->checker()->check($this->handBack($bundle, ...$credentials));
    }

    private function handBack(string $bundle, string ...$credentials): HandBack
    {
        return new HandBack($this->history->mirror, 'task', $bundle, false, new JobCredentials(...$credentials));
    }

    private function checker(string $scanner = 'gitleaks'): CheckHandBack
    {
        return new CheckHandBack(new RepositoryGit(new HardenedGit()), new BundleFile(), new StrippedCommits(), new IncomingObjects(), new BundleSecretScan($scanner));
    }
}
