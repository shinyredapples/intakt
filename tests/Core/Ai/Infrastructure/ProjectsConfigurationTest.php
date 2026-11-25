<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\CodexEffort;
use Intakt\Core\Ai\Infrastructure\ProjectsConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(ProjectsConfiguration::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(Account::class)]
#[UsesClass(CodexEffort::class)]
final class ProjectsConfigurationTest extends TestCase
{
    public function testProjectsKeepTheirTierMappings(): void
    {
        $config = $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 'mini']]]]]);

        self::assertSame(['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 'mini', 'max_output' => 4000, 'substitutes' => []]]]], $config['projects']);
    }

    public function testATierKeepsItsSubstitutesInOrder(): void
    {
        $substitutes = [['provider' => 'openai', 'model' => 'mini'], ['provider' => 'anthropic', 'model' => 'small']];
        $config = $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'claude_code', 'model' => 'sonnet', 'substitutes' => $substitutes]]]]]);

        self::assertSame(['alpha' => ['tiers' => ['light' => ['provider' => 'claude_code', 'model' => 'sonnet', 'substitutes' => $substitutes, 'max_output' => 4000]]]], $config['projects']);
    }

    public function testASubstituteNeedsAKnownProviderAndAModel(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 'mini', 'substitutes' => [['provider' => 'nowhere', 'model' => 'x']]]]]]]);
    }

    public function testEachTierHasItsOwnDefaultOutputLimit(): void
    {
        $tiers = ['strong' => ['provider' => 'anthropic', 'model' => 'a'], 'standard' => ['provider' => 'anthropic', 'model' => 'b'], 'light' => ['provider' => 'anthropic', 'model' => 'c']];

        $config = $this->process(['projects' => ['alpha' => ['tiers' => $tiers]]]);

        /** @var array{alpha: array{tiers: array<string, array{max_output: int}>}} $projects */
        $projects = $config['projects'];
        self::assertSame([16000, 8000, 4000], array_column($projects['alpha']['tiers'], 'max_output'));
    }

    public function testATierKeepsTheOutputLimitTheOwnerSets(): void
    {
        $config = $this->process(['projects' => ['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'a', 'max_output' => 32000]]]]]);

        /** @var array{alpha: array{tiers: array{strong: array{max_output: int}}}} $projects */
        $projects = $config['projects'];
        self::assertSame(32000, $projects['alpha']['tiers']['strong']['max_output']);
    }

    public function testAnOutputLimitBelowOneIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'a', 'max_output' => 0]]]]]);
    }

    public function testNoProjectsIsValid(): void
    {
        self::assertSame([], $this->process([])['projects']);
    }

    public function testAProjectsValueThatIsNoMapIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Invalid type for path "intakt\.projects"/');

        $this->process(['projects' => 'broken']);
    }

    public function testEveryProviderHasItsDefaultAccountWhenNoneIsListed(): void
    {
        $accounts = $this->process([])['accounts'];

        self::assertSame(array_fill_keys(array_column(Provider::cases(), 'value'), [Account::DEFAULT]), $accounts);
    }

    public function testListedAccountsKeepTheOwnersOrder(): void
    {
        $accounts = (array) $this->process(['accounts' => ['anthropic' => ['team', 'default']]])['accounts'];

        self::assertSame(['team', 'default'], (array) $accounts['anthropic']);
        self::assertSame([Account::DEFAULT], (array) $accounts['openai']);
    }

    /**
     * @return iterable<string, array{list<mixed>}>
     */
    public static function refusedAccountLists(): iterable
    {
        yield 'no account' => [[]];
        yield 'an uppercase name' => [['Team']];
        yield 'a name with a dot' => [['team.b']];
        yield 'a name starting with a digit' => [['2nd']];
        yield 'a name ending in a newline' => [["team\n"]];
        yield 'an account listed twice' => [['team', 'team']];
    }

    /**
     * @param list<mixed> $names
     */
    #[DataProvider('refusedAccountLists')]
    public function testAnAccountListThatCannotNameAnAccountIsRefused(array $names): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['accounts' => ['anthropic' => $names]]);
    }

    public function testAnAccountOfAnUnknownProviderIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['accounts' => ['nowhere' => ['default']]]);
    }

    public function testAnUnknownProviderIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'nowhere', 'model' => 'm']]]]]);
    }

    public function testAnUnknownTierIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['huge' => ['provider' => 'openai', 'model' => 'm']]]]]);
    }

    public function testAMappingWithoutAModelIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai']]]]]);
    }

    public function testAProjectNameKeepsItsDashes(): void
    {
        $config = $this->process(['projects' => ['my-project' => ['tiers' => []]]]);

        self::assertSame(['my-project'], array_keys((array) $config['projects']));
    }

    public function testAModelThatIsNoStringIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 5]]]]]);
    }

    #[DataProvider('blankModels')]
    public function testAModelMadeOnlyOfWhitespaceIsRefusedNamingTheKey(string $model): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.tiers\.light\.model.*The "model" key must not be blank\./');

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => $model]]]]]);
    }

    public function testAModelThatIsNotValidUtf8IsRefusedNamingTheKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.tiers\.light\.model.*must be valid UTF-8/');

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => "\xA0"]]]]]);
    }

    public function testNoCodexSectionLeavesCodexUnconfigured(): void
    {
        self::assertArrayNotHasKey('codex', $this->process([]));
    }

    public function testTheCodexModelAndEffortAreKept(): void
    {
        self::assertSame(['model' => 'gpt-6.2-sol', 'effort' => 'high'], $this->process(['codex' => ['model' => 'gpt-6.2-sol', 'effort' => 'high']])['codex']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedCodexSections(): iterable
    {
        yield 'no effort' => [['model' => 'gpt-6.2-sol']];
        yield 'no model' => [['effort' => 'high']];
        yield 'a blank model' => [['model' => ' ', 'effort' => 'high']];
        yield 'an unknown effort' => [['model' => 'gpt-6.2-sol', 'effort' => 'huge']];
        yield 'an unknown key' => [['model' => 'gpt-6.2-sol', 'effort' => 'high', 'sandbox' => 'none']];
    }

    /**
     * @param array<string, mixed> $codex
     */
    #[DataProvider('refusedCodexSections')]
    public function testACodexSectionWithoutAPinnedModelAndEffortIsRefused(array $codex): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['codex' => $codex]);
    }

    public function testATierOnCodexThatMapsThePinnedModelIsKept(): void
    {
        $processed = $this->process(['codex' => ['model' => 'gpt-6.2-sol', 'effort' => 'high'], 'projects' => ['alpha' => ['tiers' => ['strong' => ['provider' => 'codex', 'model' => 'gpt-6.2-sol']]]]]);

        self::assertStringContainsString('"strong":{"provider":"codex","model":"gpt-6.2-sol"', json_encode($processed['projects'] ?? null, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function codexMappingsOffThePin(): iterable
    {
        $pinned = ['model' => 'gpt-6.2-sol', 'effort' => 'high'];

        yield 'a tier on another model' => [['codex' => $pinned, 'projects' => ['alpha' => ['tiers' => ['strong' => ['provider' => 'codex', 'model' => 'gpt-5.4']]]]]];
        yield 'a substitute on another model' => [['codex' => $pinned, 'projects' => ['alpha' => ['tiers' => ['strong' => ['provider' => 'openai', 'model' => 'gpt-5', 'substitutes' => [['provider' => 'codex', 'model' => 'gpt-5.4']]]]]]]];
        yield 'a tier without the codex section' => [['projects' => ['alpha' => ['tiers' => ['strong' => ['provider' => 'codex', 'model' => 'gpt-6.2-sol']]]]]];
    }

    /**
     * @param array<string, mixed> $contents
     */
    #[DataProvider('codexMappingsOffThePin')]
    public function testACodexMappingOffThePinnedModelIsRefused(array $contents): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/must map the model pinned under codex\.model/');

        $this->process($contents);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankModels(): iterable
    {
        yield 'ASCII spaces' => ['   '];
        yield 'no-break space' => ["\u{00A0}"];
    }

    /**
     * @param array<string, mixed> $contents
     *
     * @return array<string, mixed>
     */
    private function process(array $contents): array
    {
        /** @var array<string, mixed> $processed */
        $processed = new Processor()->processConfiguration(new ProjectsConfiguration(), [$contents]);

        return $processed;
    }
}
