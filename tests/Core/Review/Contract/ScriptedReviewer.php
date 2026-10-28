<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Verdict;
use Intakt\Kernel;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final readonly class ScriptedReviewer
{
    public static function answering(string|ResultInterface $output): DeferredResult
    {
        return MockPlatformFactory::createPlatform(static fn (): string|ResultInterface => $output, eventDispatcher: self::theApplicationsDispatcher())
            ->invoke('reviewer', 'Review the change.', [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class]);
    }

    // The application's dispatcher, because then the subscribers and their order are the ones a real reviewer call gets.
    public static function theApplicationsDispatcher(): EventDispatcherInterface
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');

        return $dispatcher;
    }
}
