<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\UseCase\ProbeProviders;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\PromptAssembly;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Throwable;

#[AsCommand(name: 'intakt:vendor:probe', description: 'Make one real call per configured provider and print its token usage')]
final readonly class ProbeProvidersCommand
{
    private const string PROMPT = 'Answer with the single word: ok';

    public function __construct(
        private TierResolver $resolver,
        private TierInvoker $tiers,
        private NormalizerInterface $normalizer,
        private PromptAssembly $assembly = new PromptAssembly(),
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

        foreach ($targets as $provider => $target) {
            $failed = !$this->probe($io, $provider, $target) || $failed;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    // The first project and tier that maps a provider is enough, because the call checks the provider's key.
    /**
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

    /**
     * @param array{string, Tier} $target
     */
    private function probe(SymfonyStyle $io, string $provider, array $target): bool
    {
        [$project, $tier] = $target;

        try {
            $result = $this->tiers->invoke($this->resolver->resolve($project, $tier), TierRequest::of(new MessageBag($this->assembly->notice(self::PROMPT))));
            $result->asText();
            $io->writeln(sprintf('%s: %s', $provider, $this->usageLine($result)));
            // Boundary: reports the failure class and fails this provider only, because the probe must go on to the next provider.
        } catch (Throwable $failure) {
            // The class only, because a provider's message can echo the request URL or headers that carry its key.
            $io->writeln(sprintf('<error>%s: the call failed with %s</error>', $provider, $failure::class));

            return false;
        }

        return true;
    }

    private function usageLine(DeferredResult $result): string
    {
        $usage = $result->getMetadata()->get('token_usage');
        if (!$usage instanceof TokenUsageInterface) {
            return 'the provider reported no token usage';
        }

        /** @var array<string, int|string|null> $figures */
        $figures = $this->normalizer->normalize(ProbeUsage::of($usage));

        return implode(', ', array_map(
            static fn (string $name, int|string|null $value): string => $name.' '.($value ?? 'n/a'),
            array_keys($figures),
            $figures,
        ));
    }
}
