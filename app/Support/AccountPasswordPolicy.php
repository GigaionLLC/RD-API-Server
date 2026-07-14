<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Shared length boundary for every newly assigned local account password.
 *
 * Existing accounts may still sign in with an older, shorter password. The policy applies
 * only when a password is created or replaced, allowing upgrades without locking users out.
 */
final class AccountPasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 255;

    /**
     * @return list<string>
     */
    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'string',
            'min:'.self::MIN_LENGTH,
            'max:'.self::MAX_LENGTH,
        ];
    }

    public static function hasValidLength(string $password): bool
    {
        $length = mb_strlen($password);

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH;
    }

    /**
     * Enforce the boundary at services that may be called without HTTP validation.
     *
     * @throws ValidationException
     */
    public static function assertValid(string $password): void
    {
        if (self::hasValidLength($password)) {
            return;
        }

        throw ValidationException::withMessages([
            'password' => sprintf(
                'The password must be between %d and %d characters.',
                self::MIN_LENGTH,
                self::MAX_LENGTH,
            ),
        ]);
    }
}
