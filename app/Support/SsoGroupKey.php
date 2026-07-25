<?php

namespace App\Support;

/**
 * Turns an identity-provider group value into a deterministic comparison key.
 *
 * There is exactly one implementation because a mapping is stored with the key produced here
 * and matched with the key produced here. If configuration and matching ever normalized
 * differently, a mapping would look correct in the console and silently never apply, which is
 * the worst failure this feature can have: an operator has no way to tell it apart from "the
 * group claim never arrived".
 *
 * LDAP values are distinguished names, where whitespace around the `,` and `=` separators is
 * insignificant, so a DN copied out of a directory browser must match one typed by hand. OIDC
 * values are arbitrary provider strings with no such structure, so they get trimming and case
 * folding only.
 */
final class SsoGroupKey
{
    public const KIND_LDAP = 'ldap';

    public const KIND_OIDC = 'oidc';

    /** Longer than any real group value; bounds a hostile or misconfigured provider. */
    public const MAX_LENGTH = 512;

    /**
     * The normalized, human-readable form. Used for display and as the digest input.
     */
    public static function normalize(string $kind, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value, 'UTF-8');

        if ($kind === self::KIND_LDAP) {
            // A distinguished name is structured: whitespace runs are insignificant, and
            // whitespace immediately around a separator carries no meaning, so a DN pasted out of
            // a directory browser matches one typed by hand. An escaped separator (\, or \=) is
            // part of an attribute value and is left alone.
            $value = (string) preg_replace('/\s+/u', ' ', $value);
            $value = (string) preg_replace('/(?<!\\\\)\s*([,=])\s*/u', '$1', $value);
        }

        // An OIDC group is an opaque provider string with no structure to exploit, so it is only
        // trimmed and case folded. Collapsing its internal whitespace would let two genuinely
        // different provider groups collide.
        return $value;
    }

    /**
     * The stored comparison key. A digest rather than the value itself: group distinguished
     * names are longer than an indexable column, and matching is exact, so nothing is lost.
     */
    public static function digest(string $kind, string $value): string
    {
        return hash('sha256', $kind."\0".self::normalize($kind, $value));
    }

    /**
     * Whether a value is usable as a mapping key at all.
     */
    public static function isUsable(string $value): bool
    {
        $trimmed = trim($value);

        return $trimmed !== ''
            && mb_strlen($trimmed, 'UTF-8') <= self::MAX_LENGTH
            && preg_match('/[\x00-\x1f\x7f]/u', $trimmed) !== 1;
    }

    /**
     * Reduce a provider's asserted group list to the values worth comparing.
     *
     * @param  array<int|string, mixed>  $groups
     * @return list<string>
     */
    public static function usableValues(array $groups, int $limit): array
    {
        $usable = [];

        foreach ($groups as $group) {
            if (count($usable) >= $limit) {
                break;
            }

            if (! is_string($group) || ! self::isUsable($group)) {
                continue;
            }

            $usable[] = trim($group);
        }

        return array_values(array_unique($usable));
    }
}
