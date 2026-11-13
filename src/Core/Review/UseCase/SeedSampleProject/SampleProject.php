<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\SeedSampleProject;

use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final readonly class SampleProject
{
    public const string NAME = 'sample';

    public const string TASK = 'Add a shipping cost by weight and a report of a customer\'s orders; a cart of 100 or more keeps its discount.';

    // Named instead of the openai_compatible variables, because the entrypoint sets those from this token with the router as the base URL.
    public const string HUGGING_FACE_TOKEN = 'HF_TOKEN';

    // Without /v1, because the generic bridge appends /v1/chat/completions itself.
    public const string HUGGING_FACE_ROUTER = 'https://router.huggingface.co';

    // The providers one key enables, in the order the panel takes them (openai_compatible through the Hugging Face token); the others need a subscription login the demo does not ask for.
    private const array PROVIDERS = [Provider::Anthropic, Provider::OpenAi, Provider::OpenAiCompatible];

    // The router models name their router provider, because the router's default provider may refuse tools together with structured output.
    private const array MODELS = [
        Provider::Anthropic->value => ['strong' => 'claude-opus-5-5', 'standard' => 'claude-sonnet-5-5', 'light' => 'claude-haiku-5-5'],
        Provider::OpenAi->value => ['strong' => 'gpt-5', 'standard' => 'gpt-5-mini', 'light' => 'gpt-5-nano'],
        Provider::OpenAiCompatible->value => ['strong' => 'moonshotai/Kimi-K3:deepinfra', 'standard' => 'moonshotai/Kimi-K3:deepinfra', 'light' => 'moonshotai/Kimi-K3:deepinfra'],
    ];

    // Another maker's model for the second reviewer when the router carries the whole panel, so the panel still has two makers.
    private const string ROUTER_SECOND_MAKER = 'openai/gpt-oss-120b:novita';

    // List prices read on 2026-11-13 in US dollars per million tokens, so the demo's first review shows a cost; the owner can change them in the seeded file.
    private const array PRICES = [
        'claude-opus-5-5' => ['input' => 5, 'output' => 25, 'cache_read' => 0.5, 'cache_write' => 6.25],
        'claude-sonnet-5-5' => ['input' => 3, 'output' => 15, 'cache_read' => 0.3, 'cache_write' => 3.75],
        'claude-haiku-5-5' => ['input' => 1, 'output' => 5, 'cache_read' => 0.1, 'cache_write' => 1.25],
        'gpt-5' => ['input' => 1.25, 'output' => 10, 'cache_read' => 0.125],
        'gpt-5-mini' => ['input' => 0.25, 'output' => 2, 'cache_read' => 0.025],
        'gpt-5-nano' => ['input' => 0.05, 'output' => 0.4, 'cache_read' => 0.005],
        'moonshotai/Kimi-K3:deepinfra' => ['input' => 0.6, 'output' => 2.5],
        self::ROUTER_SECOND_MAKER => ['input' => 0.15, 'output' => 0.6],
    ];

    public function __construct(
        private EnabledAccounts $accounts,
        #[Autowire(env: 'default::OPENAI_COMPATIBLE_BASE_URL')]
        private ?string $compatibleBaseUrl,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function isWritten(string $configuration): bool
    {
        return $this->filesystem->exists($configuration);
    }

    /**
     * @param non-empty-list<Provider> $providers
     */
    public function write(string $configuration, array $providers): void
    {
        $this->filesystem->dumpFile($configuration, $this->configuration($providers));
    }

    /**
     * @return list<Provider>
     */
    public function keyedProviders(): array
    {
        return array_values(array_filter(self::PROVIDERS, $this->isKeyed(...)));
    }

    /**
     * @return list<string>
     */
    public function keyVariables(): array
    {
        return array_merge(...array_map(
            static fn (Provider $provider): array => Provider::OpenAiCompatible === $provider ? [self::HUGGING_FACE_TOKEN] : $provider->variables(),
            self::PROVIDERS,
        ));
    }

    // Only the Hugging Face router counts for openai_compatible, because the seeded model names are router models another endpoint would not know.
    private function isKeyed(Provider $provider): bool
    {
        if ([] === $this->accounts->of($provider)) {
            return false;
        }

        return Provider::OpenAiCompatible !== $provider || self::HUGGING_FACE_ROUTER === $this->compatibleBaseUrl;
    }

    /**
     * @param non-empty-list<Provider> $providers
     */
    private function configuration(array $providers): string
    {
        $tiers = $this->tierProviders($providers);
        $models = $this->models($tiers);
        $project = ['tiers' => $models, 'review' => $this->review($this->panel($tiers))];

        return '# Written once by intakt:sample:seed; edit it and start again to change the sample project.'.PHP_EOL
            .'# The prices are list prices read on 2026-11-13, in US dollars per million tokens.'.PHP_EOL
            .Yaml::dump(['projects' => [self::NAME => $project], 'budget' => ['prices' => $this->prices($models)]], 6, 4);
    }

    /**
     * @param non-empty-list<Provider> $providers
     *
     * @return array<string, Provider>
     */
    private function tierProviders(array $providers): array
    {
        // The second reviewer's tier goes to the last provider, so a second key brings a second company onto the panel.
        return [
            Tier::Strong->value => array_first($providers),
            Tier::Standard->value => array_last($providers),
            Tier::Light->value => array_first($providers),
        ];
    }

    /**
     * @param array<string, Provider> $tiers
     *
     * @return array<string, array{provider: string, model: string}>
     */
    private function models(array $tiers): array
    {
        $models = [];
        foreach ($tiers as $tier => $provider) {
            $models[$tier] = ['provider' => $provider->value, 'model' => $this->model($tier, $tiers)];
        }

        return $models;
    }

    /**
     * @param array<string, Provider> $tiers
     */
    private function model(string $tier, array $tiers): string
    {
        $provider = $tiers[$tier];

        if (Tier::Standard->value === $tier && Provider::OpenAiCompatible === $provider && $tiers[Tier::Strong->value] === $provider) {
            return self::ROUTER_SECOND_MAKER;
        }

        return self::MODELS[$provider->value][$tier] ?? throw new LogicException(sprintf('The sample project has no %s model on %s.', $tier, $provider->value));
    }

    /**
     * @param array<string, Provider> $tiers
     */
    private function panel(array $tiers): Panel
    {
        return new Panel([
            $this->reviewer(new ReviewerRole('correctness', 'Check that the change does what the task asks, that the existing tests still hold and that new code is tested.'), Tier::Strong, $tiers),
            $this->reviewer(new ReviewerRole('security', 'Look for ways the change lets input run commands or weakens a check.'), Tier::Standard, $tiers),
        ], QuorumPolicy::All);
    }

    /**
     * @param array<string, Provider> $tiers
     */
    private function reviewer(ReviewerRole $role, Tier $tier, array $tiers): PanelReviewer
    {
        return new PanelReviewer($role, new ReviewerModel($tier, $tiers[$tier->value]), 10);
    }

    /**
     * @return array{quorum: string, reviewers: list<array{role: string, tier: string, provider: string, prompt: string, budget: int}>}
     */
    private function review(Panel $panel): array
    {
        return [
            'quorum' => $panel->quorum->value,
            'reviewers' => array_map(static fn (PanelReviewer $reviewer): array => [
                'role' => $reviewer->role->name,
                'tier' => $reviewer->model->tier->value,
                'provider' => $reviewer->model->provider->value,
                'prompt' => $reviewer->role->prompt,
                'budget' => $reviewer->budget,
            ], $panel->reviewers),
        ];
    }

    /**
     * @param array<string, array{provider: string, model: string}> $models
     *
     * @return array<string, array<string, int|float>>
     */
    private function prices(array $models): array
    {
        $prices = [];
        foreach ($models as $tier) {
            $prices[$tier['model']] = self::PRICES[$tier['model']] ?? throw new LogicException(sprintf('The sample project has no price for %s.', $tier['model']));
        }

        return $prices;
    }
}
