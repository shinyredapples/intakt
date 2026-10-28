<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Verdict;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\StructuredOutput\Validator\ValidatorSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;

final readonly class ScriptedReviewer
{
    public static function answering(string|ResultInterface $output): DeferredResult
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());
        $dispatcher->addSubscriber(new ValidatorSubscriber());

        return MockPlatformFactory::createPlatform(static fn (): string|ResultInterface => $output, eventDispatcher: $dispatcher)
            ->invoke('reviewer', 'Review the change.', [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class]);
    }
}
