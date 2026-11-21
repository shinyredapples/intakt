<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Symfony\AI\Platform\Model;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The one per-account read that both AccountFailoverPlatform's calls and TierPlatform's selection decide by, so the two can never disagree (issue #2860); excluded from the container because each AccountFailoverPlatform builds its own over its accounts.
#[Exclude]
final readonly class ModelWaits
{
    /**
     * @param list<string> $accounts
     */
    public function __construct(
        private Provider $provider,
        private array $accounts,
        private AccountCooldowns $cooldowns,
        private PinnedProviderCooldowns $pinnedCooldowns,
    ) {
    }

    // Read without the options after a question mark, because Symfony AI's catalog takes them from the model name too. Only a router's `owner/model:provider` shape pins (`deepinfra` in `moonshotai/Kimi-K3.1-Pro:deepinfra`): exactly one slash and one colon, each part only visible ASCII other than the slash and the colon, anchored at the very end, so a native id with colons (`ft:gpt-4o-mini:org::id`), a tag without an owner (`llama3:8b`), a nested owner (`owner/group/model:router`) or a name with whitespace, a control character or any non-ASCII character pins nothing.
    public function routerProviderOf(string|Model $model): ?string
    {
        $name = explode('?', $model instanceof Model ? $model->getName() : $model, 2)[0];

        return 1 === preg_match('~^[\x21-\x2E\x30-\x39\x3B-\x7E]+/[\x21-\x2E\x30-\x39\x3B-\x7E]+:([\x21-\x2E\x30-\x39\x3B-\x7E]+)\z~', $name, $pin) ? $pin[1] : null;
    }

    // Per account the longer of its own window and its pinned provider's, each read once from its store. A wait is 0 only for an account that had neither when read, so a caller that finds no free account always has a wait of at least a second, whatever ended or started between two reads.
    /**
     * @return array<string, int>
     */
    public function perAccount(?string $routerProvider): array
    {
        $waits = [];

        foreach ($this->accounts as $name) {
            $waits[$name] = $this->ofAccount(new Account($this->provider, $name), $routerProvider);
        }

        return $waits;
    }

    // One account's wait by the same read, so AccountPlatform can check every account again right before it sends, the first free one too, since the scan that found it free goes on reading the others (issue #2860).
    public function ofAccount(Account $account, ?string $routerProvider): int
    {
        $own = $this->cooldowns->secondsUntilFree($account);

        return null === $routerProvider ? $own : max($own, $this->pinnedCooldowns->secondsUntilFree(new PinnedProvider($account, $routerProvider)));
    }

    // 0 while an account can take the model, else the shortest wait, at least a second, so a cooling model is never reported free and chosen again at once; the longest window is listed too, so min() always has a value.
    public function secondsUntilFree(string|Model $model): int
    {
        $waits = $this->perAccount($this->routerProviderOf($model));

        return in_array(0, $waits, true) ? 0 : min([AccountCooldowns::LONGEST_SECONDS, ...$waits]);
    }
}
