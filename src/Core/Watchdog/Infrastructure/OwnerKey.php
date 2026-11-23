<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

// Checked when MainHistory is built, because the owner should learn of a key ssh-keygen cannot read before any commit is judged against it.
final readonly class OwnerKey
{
    // Strict, because a quote, an option or a line break in a key would write another line or option into the allowed-signers file.
    private const string PUBLIC_KEY = '/\A(?<type>(?:sk-)?(?:ssh|ecdsa)-[a-z0-9-]+(?:@openssh\.com)?) (?<key>[A-Za-z0-9+\/]+={0,2})(?: [^\x00-\x1f\x7f"]*)?\z/';

    // Listed per type (an exact length, an exact value, or null for any field that is not empty), because the fields after the type decide whether the bytes are a whole key.
    private const array KEY_FIELDS = [
        'ssh-ed25519' => [32],
        'ssh-rsa' => [null, null],
        'ecdsa-sha2-nistp256' => ['nistp256', 65],
        'ecdsa-sha2-nistp384' => ['nistp384', 97],
        'ecdsa-sha2-nistp521' => ['nistp521', 133],
    ];

    // Derived from its plain type, because a key on a security key carries the same fields and then its application.
    private const string SECURITY_KEY = '/\Ask-(?<plain>ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com\z/';

    // OpenSSH's own minimum, because a shorter modulus is a key ssh-keygen refuses to verify with.
    private const int RSA_MINIMUM_BITS = 1024;

    // Uncompressed only, because OpenSSH reads no other point form, while OpenSSL would also decode a hybrid one.
    private const string UNCOMPRESSED_POINT = "\x04";

    // The DER head of a public key on each curve (hex), because only OpenSSL's decode tells a point on the curve from a point off it.
    private const array CURVE_KEY_HEADS = [
        'ecdsa-sha2-nistp256' => '3059301306072a8648ce3d020106082a8648ce3d030107034200',
        'ecdsa-sha2-nistp384' => '3076301006072a8648ce3d020106052b81040022036200',
        'ecdsa-sha2-nistp521' => '30819b301006072a8648ce3d020106052b8104002303818600',
    ];

    private function __construct(public string $typeAndKey)
    {
    }

    /**
     * @throws OwnerKeyRefused
     */
    public static function fromLine(string $line, string $signer, int $position): self
    {
        $blob = 1 === preg_match(self::PUBLIC_KEY, $line, $parts) ? (string) base64_decode($parts['key'], true) : '';
        if ('' === $blob || !self::isAWholeKey($blob, $parts['type'])) {
            throw OwnerKeyRefused::notAPublicKey($signer, $position);
        }

        // Without the comment and encoded anew, because only the type and the key bytes decide who signed, and the unused bits of a last base64 digit would let one key read as two.
        return new self($parts['type'].' '.base64_encode($blob));
    }

    // The whole key, because a key cut short or with bytes left over would be accepted here and then fail every check later.
    private static function isAWholeKey(string $blob, string $type): bool
    {
        $fields = self::fields($blob);
        $plain = 1 === preg_match(self::SECURITY_KEY, $type, $securityKey) ? $securityKey['plain'] : $type;
        $expected = self::KEY_FIELDS[$plain] ?? null;
        $expected = null !== $expected && $plain !== $type ? [...$expected, null] : $expected;
        if (null === $fields || null === $expected || $type !== array_shift($fields) || count($fields) !== count($expected)) {
            return false;
        }

        return array_all($expected, static fn (int|string|null $rule, int $index): bool => match (true) {
            is_int($rule) => strlen($fields[$index]) === $rule,
            is_string($rule) => $fields[$index] === $rule,
            default => '' !== $fields[$index],
        }) && self::isAKeyOpenSshReads($plain, $fields);
    }

    /**
     * @return list<string>|null
     */
    private static function fields(string $blob): ?array
    {
        $fields = [];
        $offset = 0;
        while ($offset < strlen($blob)) {
            $length = strlen($blob) - $offset < 4 ? null : unpack('N', $blob, $offset);
            // No key at all, because a length that does not fit what is left must never read as a shorter key.
            if (!is_array($length) || !is_int($length[1]) || $length[1] > strlen($blob) - $offset - 4) {
                return null;
            }
            $fields[] = substr($blob, $offset + 4, $length[1]);
            $offset += 4 + $length[1];
        }

        return $fields;
    }

    /**
     * @param list<string> $fields
     */
    private static function isAKeyOpenSshReads(string $plain, array $fields): bool
    {
        // The RSA exponent must be a positive number, and the second field holds the RSA modulus or the ECDSA point, the one part ssh-keygen judges beyond its length.
        return match ($plain) {
            'ssh-ed25519' => true,
            'ssh-rsa' => self::isAPositiveNumber($fields[0]) && self::modulusBits($fields[1]) >= self::RSA_MINIMUM_BITS,
            default => self::isOnItsCurve($plain, $fields[1]),
        };
    }

    private static function isAPositiveNumber(string $mpint): bool
    {
        // Not positive when the top bit is set, because that makes the SSH number negative, nor when only zero bytes are left.
        return '' !== $mpint && ord($mpint[0]) < 0x80 && '' !== ltrim($mpint, "\x00");
    }

    private static function modulusBits(string $modulus): int
    {
        // No bits for a negative mpint, because a set top bit makes the SSH number negative and no RSA modulus is.
        if ('' !== $modulus && ord($modulus[0]) >= 0x80) {
            return 0;
        }
        $significant = ltrim($modulus, "\x00");

        return '' === $significant ? 0 : (strlen($significant) - 1) * 8 + strlen(decbin(ord($significant[0])));
    }

    private static function isOnItsCurve(string $plain, string $point): bool
    {
        $head = self::CURVE_KEY_HEADS[$plain] ?? null;
        if (null === $head || !str_starts_with($point, self::UNCOMPRESSED_POINT)) {
            return false;
        }

        // A point of the right length may still lie off the curve, and ssh-keygen would then refuse every signature the owner makes.
        $der = hex2bin($head).$point;

        return false !== openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n");
    }
}
