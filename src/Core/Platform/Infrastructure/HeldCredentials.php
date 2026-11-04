<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Infrastructure;

use RuntimeException;
use SodiumException;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;

// Read when asked, so no secret enters the compiled container, from the sources Symfony's env processor reads: the environment, then the secrets vault.
final readonly class HeldCredentials
{
    /**
     * @param list<string> $names
     */
    public function __construct(
        private array $names,
        private ?AbstractVault $vault = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        $values = [...$this->environmentValues(), ...$this->vaultValues()];

        return array_values(array_unique(array_filter($values, static fn (mixed $value): bool => is_string($value) && '' !== $value)));
    }

    /**
     * @return list<mixed>
     */
    // Each source can hold its own value for a name (putenv() writes to neither array), so merging them by name would drop a value.
    private function environmentValues(): array
    {
        $values = [];
        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ($source as $name => $value) {
                if ($this->isHeld((string) $name)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @return list<string|null>
     */
    private function vaultValues(): array
    {
        $names = array_map(strval(...), array_keys($this->vault?->list() ?? []));

        return array_values(array_map($this->revealed(...), array_filter($names, $this->isHeld(...))));
    }

    // A wrong decryption key returns null but a key of the wrong length, or none, throws; the logger must still write, and the secret's shape is still redacted.
    private function revealed(string $name): ?string
    {
        try {
            return $this->vault?->reveal($name);
        } catch (SodiumException|RuntimeException) {
            return null;
        }
    }

    // An account other than the default reads its provider's variables with its name appended (Account::variables()), so a name holds those too.
    private function isHeld(string $variable): bool
    {
        return array_any($this->names, static fn (string $name): bool => $variable === $name || str_starts_with($variable, $name.'_'));
    }
}
