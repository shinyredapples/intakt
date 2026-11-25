<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\DocsPages;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelCannotReachQuorum;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewLane;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\TechnicalWriterTrigger;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Core\Review\Infrastructure\PanelConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(PanelConfiguration::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(ConfiguredPanels::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(PathGlob::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Decision::class)]
#[UsesClass(PanelCannotReachQuorum::class)]
#[UsesClass(ReviewLane::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(TechnicalWriterTrigger::class)]
#[UsesClass(DocsPages::class)]
final class PanelConfigurationTest extends TestCase
{
    private const array TIER_PROVIDERS = [
        'alpha' => ['strong' => 'anthropic', 'standard' => 'anthropic', 'light' => 'openai'],
        'beta' => ['light' => 'openai'],
        '7' => ['light' => 'openai'],
    ];

    public function testAPanelOfOneReviewerLoads(): void
    {
        $panel = $this->panel('all', [$this->reviewer()]);

        self::assertCount(1, $panel['reviewers']);
        self::assertSame('all', $panel['quorum']);
        self::assertSame(['role' => 'correctness', 'tier' => 'strong', 'provider' => 'anthropic', 'prompt' => 'Check the logic.', 'budget' => 5], $panel['reviewers'][0]);
    }

    public function testAPanelOfTwoReviewersLoadsWithTheMajorityPolicy(): void
    {
        $panel = $this->panel('majority', [$this->reviewer(), $this->reviewer(['role' => 'style'])]);

        self::assertCount(2, $panel['reviewers']);
        self::assertSame('majority', $panel['quorum']);
    }

    public function testAPanelOfThreeReviewersLoadsWithTheOneStrongPlusOnePolicy(): void
    {
        $panel = $this->panel('one-strong-plus-one', [$this->reviewer(), $this->reviewer(['role' => 'style']), $this->reviewer(['role' => 'tests'])]);

        self::assertSame(['correctness', 'style', 'tests'], array_column($panel['reviewers'], 'role'));
        self::assertSame('one-strong-plus-one', $panel['quorum']);
    }

    public function testAProjectWithANumericNameLoadsItsPanel(): void
    {
        $processed = $this->process(['projects' => ['7' => ['quorum' => 'all', 'reviewers' => [$this->reviewer(['tier' => 'light', 'provider' => 'openai'])]]]]);

        self::assertArrayHasKey('7', $processed['projects']);
    }

    public function testAProjectWithoutRulesLoadsWithAnEmptyRuleList(): void
    {
        $processed = $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()]]]]);

        /** @var array{rules: list<mixed>} $alpha */
        $alpha = $processed['projects']['alpha'];

        self::assertSame([], $alpha['rules']);
    }

    public function testARuleWithTriggersAndReviewersLoads(): void
    {
        $rule = ['min_lines' => 200, 'paths' => ['src/**'], 'reviewers' => [$this->reviewer(['role' => 'extra'])]];

        $processed = $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'rules' => [$rule]]]]);

        /** @var array{rules: list<array{min_lines: int, paths: list<string>}>} $alpha */
        $alpha = $processed['projects']['alpha'];

        self::assertSame(200, $alpha['rules'][0]['min_lines']);
        self::assertSame(['src/**'], $alpha['rules'][0]['paths']);
    }

    public function testATechnicalWriterWithoutPathsWatchesTheDocsPeopleRead(): void
    {
        $processed = $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'technical_writer' => $this->writer()]]]);

        /** @var array{technical_writer: array{paths: list<string>, tier: string}} $alpha */
        $alpha = $processed['projects']['alpha'];

        self::assertSame(['docs/user/**', 'docs/guide/**', 'README.md'], $alpha['technical_writer']['paths']);
        self::assertSame('standard', $alpha['technical_writer']['tier']);
    }

    public function testAProjectWithoutATechnicalWriterLoadsWithoutOne(): void
    {
        $processed = $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()]]]]);

        self::assertArrayNotHasKey('technical_writer', (array) $processed['projects']['alpha']);
    }

    public function testATechnicalWriterOnAnotherProviderThanItsTierIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote('The technical writer of project "alpha" names provider "openai", but the project maps tier "standard" to "anthropic".', '/').'/');

        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'technical_writer' => $this->writer(['provider' => 'openai'])]]]);
    }

    public function testATechnicalWriterWithAnEmptyPathListIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'technical_writer' => $this->writer(['paths' => []])]]]);
    }

    public function testAReviewerCannotTakeTheTechnicalWritersRoleName(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/The role "technical_writer" is reserved for the technical_writer section/');

        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer(['role' => ReviewerRole::TECHNICAL_WRITER])]]]]);
    }

    public function testARuleReviewerCannotTakeTheTechnicalWritersRoleName(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/reserved for the technical_writer section/');

        $rule = ['paths' => ['docs/**'], 'reviewers' => [$this->reviewer(['role' => ReviewerRole::TECHNICAL_WRITER])]];
        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'rules' => [$rule]]]]);
    }

    public function testARuleWithoutATriggerIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/needs "min_lines" or "paths"/');

        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'rules' => [['reviewers' => [$this->reviewer()]]]]]]);
    }

    public function testACorePanelThatCannotMeetItsQuorumIsRefusedEvenWhenARuleWouldMakeItReachable(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/project "alpha".*never meet the "one-strong-plus-one" quorum/');

        $rule = ['min_lines' => 500, 'reviewers' => [$this->reviewer(['role' => 'style', 'tier' => 'standard'])]];
        $this->process(['projects' => ['alpha' => ['quorum' => 'one-strong-plus-one', 'reviewers' => [$this->reviewer()], 'rules' => [$rule]]]]);
    }

    /**
     * @param list<mixed> $paths
     */
    #[DataProvider('brokenPathPatterns')]
    public function testAPathPatternThatIsNotTextIsRefused(array $paths, string $reason): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.rules\.0\.paths.*'.$reason.'/');

        $rule = ['paths' => $paths, 'reviewers' => [$this->reviewer()]];
        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'rules' => [$rule]]]]);
    }

    /**
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function brokenPathPatterns(): iterable
    {
        yield 'a number' => [[123], 'Expected "string"'];
        yield 'a boolean' => [[true], 'Expected "string"'];
        yield 'invalid UTF-8' => [["config/\xFF/**"], 'valid UTF-8'];
    }

    public function testRulesGivenAsAMapAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.rules.*list/');

        $rule = ['min_lines' => 5, 'reviewers' => [$this->reviewer()]];
        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'rules' => ['big' => $rule]]]]);
    }

    public function testPathsGivenAsAMapAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.rules\.0\.paths.*list/');

        $rule = ['paths' => ['code' => 'src/**'], 'reviewers' => [$this->reviewer()]];
        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'rules' => [$rule]]]]);
    }

    public function testARuleReviewerOnAnotherProviderThanItsTierIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/project "alpha" names provider "openai"/');

        $rule = ['min_lines' => 5, 'reviewers' => [$this->reviewer(['provider' => 'openai'])]];
        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()], 'rules' => [$rule]]]]);
    }

    public function testAnUnknownQuorumPolicyIsRefusedNamingTheKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.quorum/');

        $this->panel('unanimous', [$this->reviewer()]);
    }

    public function testAPanelWithoutAQuorumIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['reviewers' => [$this->reviewer()]]]]);
    }

    public function testAPanelWithoutReviewersIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->panel('all', []);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('brokenReviewers')]
    public function testABrokenReviewerIsRefusedNamingTheKey(array $override, string $key): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.reviewers\.0\.'.$key.'/');

        $this->panel('all', [$override + $this->reviewer()]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function brokenReviewers(): iterable
    {
        yield 'unknown tier' => [['tier' => 'huge'], 'tier'];
        yield 'unknown provider' => [['provider' => 'nowhere'], 'provider'];
        yield 'empty role' => [['role' => ''], 'role'];
        yield 'empty prompt' => [['prompt' => ''], 'prompt'];
        yield 'zero budget' => [['budget' => 0], 'budget'];
        yield 'negative budget' => [['budget' => -3], 'budget'];
        yield 'budget that is no number' => [['budget' => 'lots'], 'budget'];
    }

    #[DataProvider('missingFields')]
    public function testAReviewerWithoutAFieldIsRefused(string $field): void
    {
        $reviewer = $this->reviewer();
        unset($reviewer[$field]);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.$field.'/');

        $this->panel('all', [$reviewer]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingFields(): iterable
    {
        foreach (['role', 'tier', 'provider', 'prompt', 'budget'] as $field) {
            yield $field => [$field];
        }
    }

    public function testAnUnknownReviewerKeyIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->panel('all', [$this->reviewer(['modle' => 'x'])]);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('blankTexts')]
    public function testABlankRoleOrPromptIsRefusedNamingTheKey(array $override, string $key): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.reviewers\.0\.'.$key.'.*blank/');

        $this->panel('all', [$override + $this->reviewer()]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function blankTexts(): iterable
    {
        yield 'spaces as role' => [['role' => '   '], 'role'];
        yield 'no-break space as prompt' => [['prompt' => "\u{00A0}"], 'prompt'];
    }

    public function testReviewersGivenAsAMapAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.reviewers.*list/');

        $this->process(['projects' => ['alpha' => ['quorum' => 'all', 'reviewers' => ['logic' => $this->reviewer()]]]]);
    }

    /**
     * @param list<array<string, mixed>> $reviewers
     */
    #[DataProvider('unreachablePanels')]
    public function testAPanelThatCanNeverMeetItsQuorumIsRefused(array $reviewers): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/project "alpha".*never meet the "one-strong-plus-one" quorum/');

        $this->panel('one-strong-plus-one', $reviewers);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function unreachablePanels(): iterable
    {
        $strong = ['role' => 'correctness', 'tier' => 'strong', 'provider' => 'anthropic', 'prompt' => 'Check the logic.', 'budget' => 5];
        $light = ['tier' => 'light', 'provider' => 'openai'] + $strong;

        yield 'one reviewer' => [[$strong]];
        yield 'no strong reviewer' => [[$light, $light]];
    }

    public function testAReviewerNamingAnotherProviderThanItsTierMapsToIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/provider "openai", but the project maps tier "standard" to "anthropic"/');

        $this->panel('all', [$this->reviewer(['tier' => 'standard', 'provider' => 'openai'])]);
    }

    public function testAReviewerWithATierTheProjectDoesNotMapIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/maps tier "strong" to no provider/');

        $this->process(['projects' => ['beta' => ['quorum' => 'all', 'reviewers' => [$this->reviewer()]]]]);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function reviewer(array $override = []): array
    {
        return $override + ['role' => 'correctness', 'tier' => 'strong', 'provider' => 'anthropic', 'prompt' => 'Check the logic.', 'budget' => 5];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function writer(array $override = []): array
    {
        return $override + ['tier' => 'standard', 'provider' => 'anthropic', 'prompt' => 'Read it as a newcomer.', 'budget' => 4];
    }

    /**
     * @param list<array<string, mixed>> $reviewers
     *
     * @return array{quorum: string, reviewers: list<array<string, mixed>>}
     */
    private function panel(string $quorum, array $reviewers): array
    {
        /** @var array{quorum: string, reviewers: list<array<string, mixed>>} $panel */
        $panel = $this->process(['projects' => ['alpha' => ['quorum' => $quorum, 'reviewers' => $reviewers]]])['projects']['alpha'];

        return $panel;
    }

    /**
     * @param array<string, mixed> $contents
     *
     * @return array{projects: array<string, mixed>}
     */
    private function process(array $contents): array
    {
        /** @var array{projects: array<string, mixed>} $processed */
        $processed = new Processor()->processConfiguration(new PanelConfiguration(self::TIER_PROVIDERS), [$contents]);

        return $processed;
    }
}
