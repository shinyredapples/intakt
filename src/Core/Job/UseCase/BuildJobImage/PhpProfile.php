<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use UnexpectedValueException;

// The PHP a project's composer.json asks for, so an image with any other PHP is refused instead of used.
#[Exclude]
final readonly class PhpProfile
{
    private const string FILE = 'composer.json';
    private const string PLATFORM_VERSION = '/^\d+\.\d+(?:\.\d+)?$/D';
    private const string EXTENSION = '/^[a-z0-9][a-z0-9_-]*$/D';
    // Above any patch PHP will release, so a rule that allows it allows whatever patch a moving minor tag holds.
    private const int NEWEST_PATCH = 9999;

    /**
     * @param list<string> $extensions
     */
    private function __construct(
        public ?string $platform,
        public string $rule,
        private ConstraintInterface $constraint,
        public array $extensions,
    ) {
    }

    /**
     * @throws JobImageNotBuilt
     */
    public static function fromComposerJson(string $json): self
    {
        $composer = self::decoded($json);
        $require = self::objectIn($composer, 'require');
        $platform = self::platformOf($composer);
        $constraint = self::constraintOf($require);

        if (null === $platform && null === $constraint) {
            throw JobImageNotBuilt::withoutPhpRecord();
        }

        [$rule, $parsed] = self::ruleOf($platform, $constraint);

        return new self($platform, $rule, $parsed, self::extensionsOf([...array_keys($require), ...array_keys(self::objectIn($composer, 'require-dev'))]));
    }

    public function allows(string $version): bool
    {
        try {
            return $this->constraint->matches(new Constraint('==', new VersionParser()->normalize($version)));
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    // The minor's moving tag only when its newest patches meet the rule, otherwise the lowest patch the rule admits in it, so an exact or capped rule gets an image whose PHP meets it.
    public function imageVersionIn(string $minor): ?string
    {
        $parser = new VersionParser();
        $intervals = Intervals::get(MultiConstraint::create([$parser->parseConstraints($minor.'.*'), $this->constraint]))['numeric'];

        if ([] === $intervals) {
            return null;
        }

        if ($this->allows($minor.'.'.self::NEWEST_PATCH)) {
            return $minor;
        }

        foreach ($intervals as $interval) {
            $lowest = $this->lowestPatchFrom($interval->getStart()->getVersion());

            if (null !== $lowest) {
                return $lowest;
            }
        }

        return null;
    }

    // The patch after the start too, because a start the rule excludes (">8.4.1") or a fourth part ("8.4.1.5") admits only the next patch.
    private function lowestPatchFrom(string $start): ?string
    {
        [$major, $minor, $patch] = array_map(intval(...), array_slice(explode('.', explode('-', $start)[0]), 0, 3));

        foreach ([$patch, $patch + 1] as $candidate) {
            $version = sprintf('%d.%d.%d', $major, $minor, $candidate);

            if ($this->allows($version)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private static function decoded(string $json): array
    {
        try {
            $composer = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $broken) {
            throw JobImageNotBuilt::notJson(self::FILE, $broken->getMessage());
        }

        return self::isObject($composer) ? $composer : throw JobImageNotBuilt::notJson(self::FILE, 'not an object');
    }

    /**
     * @param array<mixed> $composer
     *
     * @return array<mixed>
     */
    private static function objectIn(array $composer, string $key): array
    {
        $object = $composer[$key] ?? [];

        return self::isObject($object) ? $object : throw JobImageNotBuilt::notAnObject(self::FILE, $key);
    }

    /**
     * @phpstan-assert-if-true array<mixed> $value
     */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ([] === $value || !array_is_list($value));
    }

    /**
     * @param array<mixed> $composer
     */
    private static function platformOf(array $composer): ?string
    {
        $config = self::objectIn($composer, 'config');
        $platform = self::objectIn($config, 'platform')['php'] ?? null;

        if (null === $platform) {
            return null;
        }

        return is_string($platform) && 1 === preg_match(self::PLATFORM_VERSION, $platform) ? $platform : throw JobImageNotBuilt::notAVersion(is_scalar($platform) ? (string) $platform : get_debug_type($platform));
    }

    /**
     * @param array<mixed> $require
     */
    private static function constraintOf(array $require): ?string
    {
        $constraint = $require['php'] ?? null;

        if (null === $constraint) {
            return null;
        }

        return is_string($constraint) ? $constraint : throw JobImageNotBuilt::notAConstraint(get_debug_type($constraint));
    }

    /**
     * @return array{string, ConstraintInterface}
     */
    private static function ruleOf(?string $platform, ?string $constraint): array
    {
        $required = null === $constraint ? null : self::parsed($constraint);

        if (null === $platform) {
            return [(string) $constraint, $required ?? throw JobImageNotBuilt::withoutPhpRecord()];
        }

        // Every patch of that minor for two parts, because Composer's own platform check reads a two-part version so.
        $exact = 3 === count(explode('.', $platform));
        $pinned = new VersionParser()->parseConstraints($exact ? $platform : $platform.'.*');

        if (null === $constraint || !$required instanceof ConstraintInterface) {
            return [$platform, $pinned];
        }

        if (!$required->matches($pinned)) {
            throw JobImageNotBuilt::platformOutsideRequire($platform, $constraint);
        }

        return [$exact ? $platform : $platform.'.* and '.$constraint, MultiConstraint::create([$pinned, $required])];
    }

    private static function parsed(string $constraint): ConstraintInterface
    {
        try {
            return new VersionParser()->parseConstraints($constraint);
        } catch (UnexpectedValueException) {
            throw JobImageNotBuilt::notAConstraint($constraint);
        }
    }

    /**
     * @param list<int|string> $packages
     *
     * @return list<string>
     */
    private static function extensionsOf(array $packages): array
    {
        $extensions = [];

        foreach ($packages as $package) {
            $name = strtolower((string) $package);

            if (!str_starts_with($name, 'ext-')) {
                continue;
            }

            $extensions[] = 1 === preg_match(self::EXTENSION, substr($name, 4)) ? substr($name, 4) : throw JobImageNotBuilt::notAnExtension((string) $package);
        }

        return array_values(array_unique($extensions));
    }
}
