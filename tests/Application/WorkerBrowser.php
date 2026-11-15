<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Closure;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

// Runs its requests as Symfony Runtime's FrankenPhpWorkerRunner does: one kernel that is never rebooted, cloned after each request when FRANKENPHP_RESET_KERNEL is set; each browser has its own cookies and so its own session.
final class WorkerBrowser extends KernelBrowser
{
    private readonly bool $resetsKernel;

    // Handed to the next request only, because a kept kernel would otherwise run it in every later request too.
    private ?Closure $step = null;

    /**
     * @param array<string, string> $server
     */
    public function __construct(KernelInterface $kernel, array $server)
    {
        parent::__construct($kernel, $server);
        $this->disableReboot();
        // Read once, because the runner reads it once before its loop.
        $this->resetsKernel = filter_var($_SERVER['FRANKENPHP_RESET_KERNEL'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, string> $server
     */
    public function requestRunning(Closure $step, string $uri, array $server): void
    {
        $this->step = $step;
        $this->request('POST', $uri, server: $server);
    }

    protected function doRequest(object $request): Response
    {
        if ($this->step instanceof Closure) {
            $request->attributes->set(RunRequestStep::ATTRIBUTE, $this->step);
            $this->step = null;
        }

        $response = parent::doRequest($request);
        if ($this->resetsKernel) {
            $this->kernel = clone $this->kernel;
        }

        return $response;
    }
}
