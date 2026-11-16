<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowError;

use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Twig\Environment;

// Wired as `framework.error_controller`, so every uncaught exception answers in the dashboard's shell instead of the framework's stock page.
#[AsController]
final readonly class ShowErrorController
{
    private const string NOT_FOUND_MESSAGE = 'This page does not exist. The address may be mistyped, or the page may have moved.';
    private const string SERVER_MESSAGE = 'Something went wrong on our side. Nothing you did caused it.';
    private const string REQUEST_MESSAGE = 'This request could not be answered.';

    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
        private FilterProjects $projects,
    ) {
    }

    public function __invoke(Throwable $exception): Response
    {
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $reference = $status >= Response::HTTP_INTERNAL_SERVER_ERROR ? $this->referenceFor($exception) : null;
        $message = $this->messageFor($status);
        // Carried over as the framework's own error page does, so a 405 still names its allowed methods.
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        return new Response($this->render($status, $message, $reference), $status, $headers);
    }

    private function messageFor(int $status): string
    {
        return match (true) {
            Response::HTTP_NOT_FOUND === $status => self::NOT_FOUND_MESSAGE,
            $status >= Response::HTTP_INTERNAL_SERVER_ERROR => self::SERVER_MESSAGE,
            default => self::REQUEST_MESSAGE,
        };
    }

    // The page shows only the reference, because the trace stays in the log, where the same reference finds it.
    private function referenceFor(Throwable $exception): string
    {
        $reference = bin2hex(random_bytes(4));
        $this->logger->error('A dashboard page failed, reference {reference}.', ['reference' => $reference, 'exception' => $exception]);

        return $reference;
    }

    private function render(int $status, string $message, ?string $reference): string
    {
        try {
            return $this->twig->render('dashboard/error.html.twig', ['status' => $status, 'message' => $message, 'reference' => $reference, 'project_names' => $this->projectNames()]);
        } catch (Throwable $failure) {
            $this->logger->warning('The error page was shown without the dashboard shell.', ['exception' => $failure]);

            return $this->plainPage($message, $reference);
        }
    }

    /**
     * The shell's project filter is its only ledger read, and the ledger may be the very thing that failed, so the shell still answers without the projects.
     *
     * @return list<string>
     */
    private function projectNames(): array
    {
        try {
            return $this->projects->names();
        } catch (Throwable $failure) {
            $this->logger->warning('The error page lists no projects, because they could not be read.', ['exception' => $failure]);

            return [];
        }
    }

    private function plainPage(string $message, ?string $reference): string
    {
        $note = null === $reference ? '' : sprintf('<p>Reference: <code>%s</code></p>', htmlspecialchars($reference));

        return sprintf('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Intakt</title></head><body><h1>Intakt</h1><p>%s</p>%s<p><a href="/">Back to the board</a></p></body></html>', htmlspecialchars($message), $note);
    }
}
