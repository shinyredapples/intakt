<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\UseCase\ProbeProviders;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'intakt:vendor:probe', description: 'Make one real call per configured provider and print its token usage')]
final readonly class ProbeProvidersCommand
{
    private const string PROMPT = 'Answer with the single word: ok';

    public function __construct(
        private TierResolver $resolver,
        private TierInvoker $tiers,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $targets = $this->targets();

        if ([] === $targets) {
            $io->warning('No enabled provider is configured.');

            return Command::SUCCESS;
        }

        $failed = false;

        foreach ($targets as $provider => [$project, $tier]) {
            $failed = !$this->probe($io, $provider, $project, $tier) || $failed;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The first project and tier that maps each enabled provider.
     *
     * @return array<string, array{string, Tier}>
     */
    private function targets(): array
    {
        $targets = [];

        foreach ($this->resolver->projects() as $project) {
            foreach (Tier::cases() as $tier) {
                $provider = $this->providerOf($project, $tier);
                if ($provider instanceof Provider) {
                    $targets[$provider->value] ??= [$project, $tier];
                }
            }
        }

        return $targets;
    }

    private function providerOf(string $project, Tier $tier): ?Provider
    {
        try {
            return $this->resolver->resolve($project, $tier)->provider;
        } catch (TierResolutionFailure) {
            return null;
        }
    }

    private function probe(SymfonyStyle $io, string $provider, string $project, Tier $tier): bool
    {
        try {
            $result = $this->tiers->invoke($project, $tier, new TierRequest(new MessageBag(Message::ofUser(self::PROMPT))));
            $result->asText();
        } catch (Throwable $failure) {
            $io->error(sprintf('%s: %s (%s)', $provider, $failure->getMessage(), $failure::class));

            return false;
        }

        $io->writeln(sprintf('%s: %s', $provider, $this->usageLine($result)));

        return true;
    }

    private function usageLine(DeferredResult $result): string
    {
        $usage = $result->getMetadata()->get('token_usage');
        if (!$usage instanceof TokenUsageInterface) {
            return 'the provider reported no token usage';
        }

        $figures = [
            'model' => $usage->getModel(),
            'prompt' => $usage->getPromptTokens(),
            'completion' => $usage->getCompletionTokens(),
            'cached' => $usage->getCachedTokens(),
            'total' => $usage->getTotalTokens(),
            'remaining' => $usage->getRemainingTokens(),
        ];

        return implode(', ', array_map(
            static fn (string $name, int|string|null $value): string => $name.' '.($value ?? 'n/a'),
            array_keys($figures),
            $figures,
        ));
    }
}
