<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\FirstRun;

use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\KeptProviderKeys;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Dashboard\Domain\FirstReviewStep;
use Intakt\Core\Dashboard\Domain\FirstRun;
use Intakt\Core\Dashboard\Domain\PanelOfProject;
use Intakt\Core\Dashboard\Domain\ProjectStep;
use Intakt\Core\Dashboard\Domain\ProviderStep;
use Intakt\Core\Dashboard\Domain\TierLine;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\PanelConfigurationFailure;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Plugin\Vendor\Contract\ProviderCatalog;
use Intakt\Plugin\Vendor\Contract\ProviderDescriptor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Reads the same sources as intakt:ai:tiers and the project configuration, so the page shows what the install really has.
final readonly class FirstRunSteps
{
    /**
     * @param array<string, string> $disabledProviders
     */
    public function __construct(
        private TierResolver $tiers,
        private EnabledAccounts $accounts,
        private KeptProviderKeys $keptKeys,
        private ProviderCatalog $providerCatalog,
        private Panels $panels,
        private LedgerReviewPages $reviews,
        private SampleChangeSource $sample,
        #[Autowire(param: AiServices::DISABLED_PROVIDERS)]
        private array $disabledProviders,
    ) {
    }

    public function firstRun(): FirstRun
    {
        $projects = [...$this->tiers->projects()];

        return new FirstRun(
            $this->providers(array_first($projects)),
            new ProjectStep(array_map($this->panelOf(...), $projects)),
            new FirstReviewStep($this->reviews->firstReview(), $this->sample->source() instanceof ChangeSource),
        );
    }

    private function providers(?string $firstProject): ProviderStep
    {
        $keyed = array_filter(Provider::cases(), fn (Provider $provider): bool => [] !== $this->accounts->of($provider));

        $kept = null;
        $unreadable = false;

        try {
            $kept = $this->keptKeys->configured();
        } catch (KeptProviderKeyUnreadable) {
            $unreadable = true;
        }

        return new ProviderStep(
            array_values(array_map(static fn (Provider $provider): string => $provider->value, $keyed)),
            array_values($this->disabledProviders),
            $firstProject,
            null === $firstProject ? [] : $this->tiersOf($firstProject),
            $kept,
            $unreadable,
            array_map(static fn (ProviderDescriptor $descriptor): string => $descriptor->label(), $this->providerCatalog->descriptors()),
        );
    }

    /**
     * @return list<TierLine>
     */
    private function tiersOf(string $project): array
    {
        return array_map(function (Tier $tier) use ($project): TierLine {
            try {
                $resolved = $this->tiers->resolve($project, $tier);
            } catch (TierResolutionFailure $failure) {
                return new TierLine($tier->value, '-', $failure->getMessage());
            }

            return new TierLine($tier->value, $resolved->provider->value, $resolved->model);
        }, Tier::cases());
    }

    private function panelOf(string $project): PanelOfProject
    {
        try {
            // A change with no lines, paths or labels matches no rule, so this is the core panel every change gets.
            $panel = $this->panels->panelForChange($project, new ChangeDescription(0, new Paths(), new Labels()));
        } catch (PanelConfigurationFailure $failure) {
            return new PanelOfProject($project, $failure->getMessage());
        }

        $reviewers = array_map(
            static fn (PanelReviewer $reviewer): string => sprintf('%s (%s tier on %s)', $reviewer->role->name, $reviewer->model->tier->value, $reviewer->model->provider->value),
            $panel->reviewers,
        );

        return new PanelOfProject($project, 'Reviewers: '.implode(', ', $reviewers).'.');
    }
}
