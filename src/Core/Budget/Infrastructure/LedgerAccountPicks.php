<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\AccountPick;
use Intakt\Core\Budget\Contract\AccountPicks;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

// The account names are stored without the provider, because the row's subject and its provider key already name it.
final readonly class LedgerAccountPicks implements AccountPicks
{
    public function __construct(
        private Ledger $ledger,
        private LedgerPayloads $payloads,
        #[Target('stored')]
        private SerializerInterface $serializer,
        private LoggerInterface $logger,
    ) {
    }

    // A refused append is logged and the call still starts, because a missing record must not stop the work it describes.
    #[Override]
    public function record(Provider $provider, RankedAccounts $pick): void
    {
        $payload = $this->serializer->serialize($this->payloadOf($provider, $pick), JsonEncoder::FORMAT);
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::AccountPicked, $payload, $provider->value));

        if (!$appended->succeeded()) {
            $this->logger->error('An account pick was not recorded.', ['pick' => $payload, 'reason' => $appended->failure]);
        }
    }

    #[Override]
    public function latestOf(Provider $provider): ?AccountPick
    {
        $newest = $this->ledger->newestOfKindAbout(LedgerEventKind::AccountPicked, $provider->value);

        return $newest instanceof RecordedEvent ? $this->pickOf($newest, $provider) : null;
    }

    private function payloadOf(Provider $provider, RankedAccounts $pick): AccountPicked
    {
        $leftOut = array_map(static fn (LeftOutAccount $left): PickedLeftOut => new PickedLeftOut($left->account->name, $left->cause, $left->resetsAt?->format(DATE_ATOM)), $pick->leftOut);

        return new AccountPicked($provider, array_first($pick->inOrder)?->name, $leftOut);
    }

    // A row of another provider or with a name an account cannot have reads as none, because the ledger is read back as untrusted text.
    private function pickOf(RecordedEvent $recorded, Provider $provider): ?AccountPick
    {
        $row = $this->payloads->of($recorded, AccountPicked::class);
        if (!$row instanceof AccountPicked || $provider !== $row->provider) {
            return null;
        }

        $names = [...(null === $row->picked ? [] : [$row->picked]), ...array_map(static fn (PickedLeftOut $left): string => $left->account, $row->leftOut)];
        if (!array_all($names, static fn (string $name): bool => 1 === preg_match(Account::NAME_PATTERN, $name))) {
            return null;
        }

        $resets = array_map(self::resetOf(...), $row->leftOut);
        if (in_array(false, $resets, true)) {
            return null;
        }

        $leftOut = array_map(static fn (PickedLeftOut $left, ?DateTimeImmutable $reset): LeftOutAccount => new LeftOutAccount(new Account($provider, $left->account), $left->cause, $reset), $row->leftOut, $resets);

        return new AccountPick($provider, null === $row->picked ? null : new Account($provider, $row->picked), $leftOut, $recorded->recordedAt);
    }

    // A reset that does not format back to its own text is refused, because PHP rolls February 30 over into March and accepts other offset forms.
    private static function resetOf(PickedLeftOut $left): DateTimeImmutable|false|null
    {
        if (null === $left->resetsAt) {
            return null;
        }

        $reset = DateTimeImmutable::createFromFormat(DATE_ATOM, $left->resetsAt);

        return false !== $reset && $reset->format(DATE_ATOM) === $left->resetsAt ? $reset : false;
    }
}
