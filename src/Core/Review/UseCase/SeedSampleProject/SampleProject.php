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
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Core\Review\Contract\SampleProjectData;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final readonly class SampleProject
{
    public const string NAME = SampleChange::PROJECT;

    public const string TASK = SampleChange::TASK;

    // Named instead of the openai_compatible variables, because the entrypoint sets those from this token with the router as the base URL.
    public const string HUGGING_FACE_TOKEN = 'HF_TOKEN';

    // The providers one key enables, in the order the panel takes them (openai_compatible through the Hugging Face token); the others need a subscription login the demo does not ask for.
    private const array PROVIDERS = [Provider::Anthropic, Provider::OpenAi, Provider::OpenAiCompatible];

    public function __construct(
        private EnabledAccounts $accounts,
        #[Autowire(env: 'default::OPENAI_COMPATIBLE_BASE_URL')]
        private ?string $compatibleBaseUrl,
        private SampleProjectData $data = new SampleProjectData(),
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
            static fn (Provider $provider): array => Provider::OpenAiCompatible === $provider ? [self::HUGGING_FACE_TOKEN] : [...$provider->variables()],
            self::PROVIDERS,
        ));
    }

    // Only the Hugging Face router counts for openai_compatible, because the seeded model names are router models another endpoint would not know.
    private function isKeyed(Provider $provider): bool
    {
        if ([] === $this->accounts->of($provider)) {
            return false;
        }

        return Provider::OpenAiCompatible !== $provider || $this->data->routerUrl()->toRawString() === $this->compatibleBaseUrl;
    }

    /**
     * @param non-empty-list<Provider> $providers
     */
    private function configuration(array $providers): string
    {
        $tiers = $this->tierProviders($providers);
        $models = $this->models($tiers);
        $project = ['tiers' => $models, 'review' => $this->review($this->panel($tiers))];
        $priceDate = $this->data->priceDate();

        return '# Written once by intakt:sample:seed; edit it and start again to change the sample project.'.PHP_EOL
            .'# The price table is dated '.$priceDate.'; the prices are list prices in US dollars per million tokens.'.PHP_EOL
            .Yaml::dump(['projects' => [self::NAME => $project], 'budget' => ['price_date' => $priceDate, 'prices' => $this->prices($models)]], 6, 4);
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
            return $this->data->secondLab();
        }

        return $this->data->model($provider, Tier::from($tier));
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
            $prices[$tier['model']] = iterator_to_array($this->data->price($tier['model']));
        }

        return $prices;
    }
}
