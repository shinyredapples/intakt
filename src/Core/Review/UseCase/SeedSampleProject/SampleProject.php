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
use Symfony\Component\Yaml\Yaml;

final readonly class SampleProject
{
    public const string NAME = 'sample';

    public const string TASK = 'Add a shipping cost by weight and a report of a customer\'s orders; a cart of 100 or more keeps its discount.';

    // The providers a key alone enables, in the order the panel takes them; the others need a base URL or a subscription login the demo does not ask for.
    private const array PROVIDERS = [Provider::Anthropic, Provider::OpenAi];

    private const array MODELS = [
        'anthropic' => ['strong' => 'claude-opus-5-5', 'standard' => 'claude-sonnet-5-5', 'light' => 'claude-haiku-5-5'],
        'openai' => ['strong' => 'gpt-5', 'standard' => 'gpt-5-mini', 'light' => 'gpt-5-nano'],
    ];

    public function __construct(private EnabledAccounts $accounts)
    {
    }

    /**
     * @return list<Provider>
     */
    public function keyedProviders(): array
    {
        return array_values(array_filter(self::PROVIDERS, fn (Provider $provider): bool => [] !== $this->accounts->of($provider)));
    }

    /**
     * @return list<string>
     */
    public function keyVariables(): array
    {
        return array_merge(...array_map(static fn (Provider $provider): array => $provider->variables(), self::PROVIDERS));
    }

    /**
     * @param non-empty-list<Provider> $providers
     */
    public function configuration(array $providers): string
    {
        $tiers = $this->tierProviders($providers);
        $project = ['tiers' => $this->models($tiers), 'review' => $this->review($this->panel($tiers))];

        return '# Written once by intakt:sample:seed; edit it and start again to change the sample project.'.PHP_EOL
            .Yaml::dump(['projects' => [self::NAME => $project]], 6, 4);
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
            $models[$tier] = [
                'provider' => $provider->value,
                'model' => self::MODELS[$provider->value][$tier] ?? throw new LogicException(sprintf('The sample project has no %s model on %s.', $tier, $provider->value)),
            ];
        }

        return $models;
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
}
