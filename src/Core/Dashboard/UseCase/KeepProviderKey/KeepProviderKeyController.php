<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\KeepProviderKey;

use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\KeptProviderKeyNotWritten;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\FormPress;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Limit: the owner session of the dashboard design does not exist yet, so the Host check, the CSRF token and the refusal while a key is configured are all that guard this route.
final readonly class KeepProviderKeyController
{
    public const string TOKEN_ID = 'dashboard_keep_provider_key';
    public const string FIELD = 'key';

    private const string FLASH_SUCCESS = 'success';
    private const string FLASH_ERROR = 'error';

    public function __construct(
        private KeepProviderKey $keeping,
        private EnabledAccounts $accounts,
        private FormPress $press,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/setup/provider-key', name: DashboardRoutes::KEEP_PROVIDER_KEY, methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $this->press->refuseWithoutToken($request, self::TOKEN_ID, 'The form token is missing or wrong, so no key was kept.');
        $this->press->flash($request, $this->refusalWhileConfigured() ?? $this->kept($request->request->getString(self::FIELD)));

        return new RedirectResponse($this->urls->generate(DashboardRoutes::FIRST_RUN), Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array{string, string}|null
     */
    private function refusalWhileConfigured(): ?array
    {
        try {
            $configured = $this->keeping->configured() instanceof ConfiguredKey || $this->anAccountIsEnabled();
        } catch (KeptProviderKeyUnreadable $unreadable) {
            return [self::FLASH_ERROR, $unreadable->getMessage().' Fix or remove the kept key, or set the key in .env.local.'];
        }

        return $configured ? [self::FLASH_ERROR, 'A provider key is already configured, so no key was kept. To change it, edit .env.local.'] : null;
    }

    private function anAccountIsEnabled(): bool
    {
        return array_any(Provider::cases(), fn (Provider $provider): bool => [] !== $this->accounts->of($provider));
    }

    /**
     * @return array{string, string}
     */
    private function kept(#[SensitiveParameter] string $key): array
    {
        try {
            $configured = $this->keeping->keep($key);
        } catch (KeyNotRecognised) {
            return [self::FLASH_ERROR, 'That is not an Anthropic key, an OpenAI key or a Hugging Face token, so no key was kept.'];
        } catch (KeyRefusedByProvider) {
            return [self::FLASH_ERROR, 'The provider refused the key, so it was not kept.'];
        } catch (KeyNotChecked) {
            return [self::FLASH_ERROR, 'The provider could not be asked to check the key, so it was not kept. Try again.'];
        } catch (KeptProviderKeyNotWritten) {
            return [self::FLASH_ERROR, 'The provider accepted the key, but it could not be written to the data folder.'];
        }

        return [self::FLASH_SUCCESS, sprintf('The %s key ending in %s is kept. Restart Intakt to use it.', $configured->kind->value, $configured->lastCharacters)];
    }
}
