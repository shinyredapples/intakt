<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StartReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\RoundSource;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\StartReview;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

// Limit: the owner session of the dashboard design does not exist yet, so the Host check and the CSRF token are all that guard this route.
final readonly class StartReviewController
{
    public const string TOKEN_ID = 'dashboard_start_review';
    public const string FLASH_SUCCESS = 'success';
    public const string FLASH_ERROR = 'error';

    public function __construct(
        private LedgerReviewPages $reviews,
        private MessageBusInterface $commands,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/reviews/{round}/start', name: DashboardRoutes::START_REVIEW, methods: ['POST'])]
    public function __invoke(Request $request, string $round): Response
    {
        $this->refuseWithoutToken($request);
        $review = $this->reviews->reviewOf($round);
        $source = $review?->source();

        if (!$review instanceof Review || !$source instanceof RoundSource) {
            throw new NotFoundHttpException('The ledger holds no review with this round that can be started again.');
        }

        $this->flash($request, $this->startFrom($review, $source));

        return new RedirectResponse($this->urls->generate(DashboardRoutes::SHOW_REVIEW, ['round' => $round]), Response::HTTP_SEE_OTHER);
    }

    private function refuseWithoutToken(Request $request): void
    {
        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->tokens->isTokenValid(new CsrfToken(self::TOKEN_ID, $token))) {
            throw new BadRequestHttpException('The form token is missing or wrong, so nothing was started.');
        }
    }

    /**
     * @return array{string, string}
     */
    // Built from the ledger and not from the form, because the repository path of a request would be an input to read the disk with.
    private function startFrom(Review $review, RoundSource $source): array
    {
        $command = new StartReview($review->project, $review->change, $source->task, new ChangeSource($source->repository, $source->base));

        try {
            $handled = $this->commands->dispatch($command)->last(HandledStamp::class);
        } catch (HandlerFailedException $failed) {
            $reportable = array_first($failed->getWrappedExceptions(ReportableFailure::class, true));

            return [self::FLASH_ERROR, $reportable?->getMessage() ?? 'The review could not be started.'];
        }

        return [self::FLASH_SUCCESS, 'Started round '.(is_string($handled?->getResult()) ? $handled->getResult() : '').'.'];
    }

    /**
     * @param array{string, string} $line
     */
    private function flash(Request $request, array $line): void
    {
        $session = $request->getSession();
        assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add(...$line);
    }
}
