<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\AutowireInline;
use Symfony\Component\HttpClient\Recorder\Redactor\DefaultRedactor;
use Symfony\Component\HttpClient\Recorder\Redactor\RedactorInterface;
use Symfony\Component\VarDumper\Cloner\Data;

// The names come from HttpClient's DefaultRedactor, so its list of credential headers and fields has one home.
final readonly class CredentialValues
{
    private const string PROBE = 'probe';

    public function __construct(
        #[AutowireInline(DefaultRedactor::class)]
        private RedactorInterface $names,
    ) {
    }

    public function isCredentialHeader(string $name): bool
    {
        return [self::PROBE] !== ($this->names->redactHeaders([$name => [self::PROBE]])[$name] ?? null);
    }

    /**
     * @param array<mixed> $headers
     *
     * @return list<string>
     */
    public function inHeaders(array $headers): array
    {
        $credentials = [];

        foreach ($headers as $name => $values) {
            if ($this->isCredentialHeader((string) $name)) {
                $credentials = [...$credentials, ...array_merge([], ...array_map($this->withToken(...), $this->strings([$values])))];
            }
        }

        return $credentials;
    }

    // Through the redactor's own form masking, because a nested name such as user[password] must count as it does there.
    /**
     * @param array<mixed> $fields
     *
     * @return list<string>
     */
    public function inFields(array $fields): array
    {
        $pairs = explode('&', http_build_query($fields, '', '&', PHP_QUERY_RFC3986));
        $masked = explode('&', (string) $this->names->redactBody(implode('&', $pairs)));
        $values = [];

        foreach ($pairs as $index => $pair) {
            if (($masked[$index] ?? $pair) !== $pair) {
                $values[] = rawurldecode(explode('=', $pair, 2)[1] ?? '');
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function strings(mixed $value): array
    {
        if (is_array($value)) {
            return array_merge([], ...array_map($this->strings(...), array_values($value)));
        }

        return is_string($value) ? [$value] : [];
    }

    // Unwrapped, because a collector that already ran its late collect holds VarDumper data instead of plain arrays.
    public function raw(mixed $value): mixed
    {
        if ($value instanceof Data) {
            return $value->getValue(true);
        }

        return is_array($value) ? array_map($this->raw(...), $value) : $value;
    }

    /**
     * @return array<mixed>
     */
    public function rawArray(mixed $value): array
    {
        $raw = $this->raw($value);

        return is_array($raw) ? $raw : [];
    }

    // The part after the scheme too, because a firewall keeps the bare token of "Bearer <token>".
    /**
     * @return list<string>
     */
    private function withToken(string $value): array
    {
        return [$value, ...(1 === preg_match('/^\S+\s+(\S.*)$/', $value, $token) ? [$token[1]] : [])];
    }
}
