<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowError;

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
    ) {
    }

    public function __invoke(Throwable $exception): Response
    {
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $reference = $status >= Response::HTTP_INTERNAL_SERVER_ERROR ? $this->referenceFor($exception) : null;
        $message = $this->messageFor($status);

        return new Response($this->render($status, $message, $reference), $status);
    }

    private function messageFor(int $status): string
    {
        return match (true) {
            Response::HTTP_NOT_FOUND === $status => self::NOT_FOUND_MESSAGE,
            $status >= Response::HTTP_INTERNAL_SERVER_ERROR => self::SERVER_MESSAGE,
            default => self::REQUEST_MESSAGE,
        };
    }

    // The reference is the only thing the page shows of the failure; the log line carries the same reference and the exception.
    private function referenceFor(Throwable $exception): string
    {
        $reference = bin2hex(random_bytes(4));
        $this->logger->error('A dashboard page failed, reference {reference}.', ['reference' => $reference, 'exception' => $exception]);

        return $reference;
    }

    private function render(int $status, string $message, ?string $reference): string
    {
        try {
            return $this->twig->render('dashboard/error.html.twig', ['status' => $status, 'message' => $message, 'reference' => $reference]);
        } catch (Throwable $failure) {
            // The shell reads the ledger, which may be the very thing that failed, so a page without it still answers.
            $this->logger->warning('The error page was shown without the dashboard shell.', ['exception' => $failure]);

            return $this->plainPage($message, $reference);
        }
    }

    private function plainPage(string $message, ?string $reference): string
    {
        $note = null === $reference ? '' : sprintf('<p>Reference: <code>%s</code></p>', htmlspecialchars($reference));

        return sprintf('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Intakt</title></head><body><h1>Intakt</h1><p>%s</p>%s<p><a href="/">Back to the board</a></p></body></html>', htmlspecialchars($message), $note);
    }
}
