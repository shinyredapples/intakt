<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowError;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\DashboardHost;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\FormIsRefused;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Twig\Environment;

// Wired as `framework.error_controller`, so every uncaught exception answers in the dashboard's shell instead of the framework's stock page; a route outside the dashboard gets the framework's answer in the requested format.
#[AsController]
final readonly class ShowErrorController
{
    private const string NOT_FOUND_MESSAGE = 'This page does not exist. The address may be mistyped, or the page may have moved.';
    private const string SERVER_MESSAGE = 'Something went wrong on our side. Nothing you did caused it.';
    private const string DASHBOARD_LOG = 'A dashboard page failed, reference {reference}.';
    private const string PLAIN_LOG = 'A request failed, reference {reference}.';
    private const string REQUEST_MESSAGE = 'This request could not be answered.';
    private const string REFUSED_FORM_HINT = 'Go back, reload the page and press the button again.';

    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
        private FilterProjects $projects,
        private DashboardHost $dashboard,
        #[Autowire(service: 'error_renderer')]
        private ErrorRendererInterface $errorRenderer,
        private RequestStack $requests,
    ) {
    }

    public function __invoke(Throwable $exception, Request $request): Response
    {
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $outside = $this->isOutsideDashboard($request);
        $reference = $status >= Response::HTTP_INTERNAL_SERVER_ERROR ? $this->referenceFor($exception, $outside ? self::PLAIN_LOG : self::DASHBOARD_LOG) : null;
        // Carried over as the framework's own error page does, so a 405 still names its allowed methods.
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        if ($outside) {
            return $this->plainAnswer($exception, $status, $headers);
        }

        $message = $this->messageFor($exception, $status);
        // A missing route fails before RefuseForeignHost has a route to judge, so the shell and its project names are kept from a foreign host here.
        $page = $this->dashboard->isNamedBy($request) ? $this->render($status, $message, $reference) : $this->plainPage($message, $reference);

        return new Response($page, $status, $headers);
    }

    // The error sub-request carries no route name, so the failing request one level up the stack is judged; one that matched no route (a 404 or a 405) stays on the dashboard's pages.
    private function isOutsideDashboard(Request $request): bool
    {
        $route = ($this->requests->getParentRequest() ?? $request)->attributes->get('_route');

        return is_string($route) && !str_starts_with($route, DashboardRoutes::PREFIX);
    }

    // The page shows only the reference, because the trace stays in the log, where the same reference finds it.
    private function referenceFor(Throwable $exception, string $log): string
    {
        $reference = bin2hex(random_bytes(4));
        $this->logger->error($log, ['reference' => $reference, 'exception' => $exception]);

        return $reference;
    }

    /**
     * @param array<mixed> $headers
     */
    private function plainAnswer(Throwable $exception, int $status, array $headers): Response
    {
        $rendered = $this->errorRenderer->render($exception);

        return new Response($rendered->getAsString(), $status, [...$rendered->getHeaders(), ...$headers]);
    }

    private function messageFor(Throwable $exception, int $status): string
    {
        return match (true) {
            $exception instanceof FormIsRefused => $exception->getMessage().' '.self::REFUSED_FORM_HINT,
            Response::HTTP_NOT_FOUND === $status => self::NOT_FOUND_MESSAGE,
            $status >= Response::HTTP_INTERNAL_SERVER_ERROR => self::SERVER_MESSAGE,
            default => self::REQUEST_MESSAGE,
        };
    }

    private function render(int $status, string $message, ?string $reference): string
    {
        try {
            return $this->twig->render('dashboard/error.html.twig', ['status' => $status, 'message' => $message, 'reference' => $reference, 'project_names' => $this->projectNames()]);
            // Boundary: logs a failed shell and answers with the plain page, because the error page itself must never fail.
        } catch (Throwable $failure) {
            $this->logger->warning('The error page was shown without the dashboard shell.', ['exception' => $failure]);

            return $this->plainPage($message, $reference);
        }
    }

    // The shell's project filter is its only ledger read, and the ledger may be the very thing that failed, so the shell still answers without the projects.
    /**
     * @return list<string>
     */
    private function projectNames(): array
    {
        try {
            return $this->projects->names();
            // Boundary: logs an unreadable ledger and lists no projects, because the error page must answer even when the ledger is what failed.
        } catch (Throwable $failure) {
            $this->logger->warning('The error page lists no projects, because they could not be read.', ['exception' => $failure]);

            return [];
        }
    }

    private function plainPage(string $message, ?string $reference): string
    {
        $note = null === $reference ? '' : sprintf('<p>Reference: <code>%s</code></p>', htmlspecialchars($reference));

        return sprintf('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Intakt</title></head><body><h1>Intakt</h1><p>%s</p>%s<p><a href="/?board=1">Back to the board</a></p></body></html>', htmlspecialchars($message), $note);
    }
}
