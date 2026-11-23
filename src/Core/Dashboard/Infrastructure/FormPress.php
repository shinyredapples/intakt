<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

// One home for the token check and the flash line, because every button route of the dashboard needs both.
final readonly class FormPress
{
    public function __construct(private CsrfTokenManagerInterface $tokens)
    {
    }

    public function refuseWithoutToken(Request $request, string $tokenId, string $refusal): void
    {
        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->tokens->isTokenValid(new CsrfToken($tokenId, $token))) {
            throw new FormIsRefused($refusal);
        }
    }

    /**
     * @param array{string, string} $line
     */
    public function flash(Request $request, array $line): void
    {
        $session = $request->getSession();
        assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add(...$line);
    }
}
