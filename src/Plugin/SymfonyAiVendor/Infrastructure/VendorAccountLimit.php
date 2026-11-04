<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Plugin\SymfonyAiVendor\Contract\SymfonyAiVendorServices;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Semaphore\SemaphoreFactory;

// A Semaphore per vendor account, tried without waiting, because a worker that waits for a place holds its message and the connection while it does.
final readonly class VendorAccountLimit
{
    // The longest a review may run (RoundReviewer::REVIEW_CEILING), so a place of a killed worker is free again by then.
    private const float CALL_CEILING_SECONDS = 3600.0;

    public function __construct(
        private SemaphoreFactory $semaphores,
        #[Autowire(param: SymfonyAiVendorServices::ACCOUNT_PARALLEL_CALLS)]
        private int $parallelCalls,
    ) {
    }

    /**
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T
     *
     * @throws VendorAccountBusy
     */
    public function run(Provider $account, Closure $call): mixed
    {
        $semaphore = $this->semaphores->createSemaphore('vendor-account-'.$account->value, $this->parallelCalls, ttlInSecond: self::CALL_CEILING_SECONDS);

        if (!$semaphore->acquire()) {
            throw VendorAccountBusy::at($account->value);
        }

        try {
            return $call();
        } finally {
            $semaphore->release();
        }
    }
}
