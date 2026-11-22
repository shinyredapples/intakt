<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final readonly class ScriptedReviewer
{
    private function __construct(public EventDispatcherInterface $dispatcher)
    {
    }

    // The application's dispatcher, because then the subscribers and their order are the ones a real reviewer call gets.
    public static function in(ContainerInterface $container): self
    {
        return new self($container->get('event_dispatcher'));
    }

    public function answering(string|ResultInterface $output): DeferredResult
    {
        return MockPlatformFactory::createPlatform(static fn (): string|ResultInterface => $output, eventDispatcher: $this->dispatcher)
            ->invoke('reviewer', 'Review the change.', [PlatformSubscriber::RESPONSE_FORMAT => ReviewerAnswer::class]);
    }

    // Read as the reviewer agent reads an answer: the answer document mapped to the contract verdict, failures turned into VerdictUnreadable.
    public function verdictFor(string|ResultInterface $output): Verdict
    {
        $result = $this->answering($output);

        return new VerdictParser()->parseWith(static fn (): Verdict => $result->asObject(ReviewerAnswer::class)->toVerdict());
    }
}
